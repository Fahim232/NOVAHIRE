<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (!isset($_SESSION['company_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$company_id   = (int)$_SESSION['company_id'];
$company_name = $_SESSION['company_name'] ?? 'Company';

// Backend Verification Guard
require_once __DIR__ . '/../includes/company_verification.php';
if (!is_company_verified($con, $company_id)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(403);
        die('Company verification required to schedule candidate interviews.');
    }
    header('Location: ' . BASE_URL . '/company/verification.php?notice=verification_required');
    exit;
}
$success_msg  = '';
$error_msg    = '';
$csrf_token   = $_SESSION['csrf_token'] ?? '';

// Ensure interviews table has Phase 2 columns (defensive)
mysqli_query($con, "CREATE TABLE IF NOT EXISTS `interviews` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `application_id` int(11) NOT NULL,
    `company_id` int(11) NOT NULL,
    `user_id` int(11) NOT NULL,
    `job_id` int(11) NOT NULL,
    `interviewer_id` int(11) DEFAULT NULL,
    `round_number` int(11) NOT NULL DEFAULT 1,
    `title` varchar(150) NOT NULL DEFAULT 'Technical Interview',
    `interview_date` date NOT NULL,
    `interview_time` time NOT NULL,
    `duration_minutes` int(11) NOT NULL DEFAULT 30,
    `end_time` time DEFAULT NULL,
    `interview_type` enum('Online','Phone','In-Person') NOT NULL DEFAULT 'Online',
    `location` varchar(255) DEFAULT NULL,
    `meeting_link` varchar(255) DEFAULT NULL,
    `notes` text DEFAULT NULL,
    `status` enum('scheduled','completed','cancelled') DEFAULT 'scheduled',
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `application_id` (`application_id`),
    KEY `company_id` (`company_id`),
    KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// --- Handle Mark Completed ---
if (isset($_POST['mark_completed'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $error_msg = "Security token mismatch. Please try again.";
    } else {
        $int_id = intval($_POST['interview_id']);
        $stmt = mysqli_prepare($con, "UPDATE interviews SET status='completed' WHERE id=? AND company_id=?");
        mysqli_stmt_bind_param($stmt, "ii", $int_id, $company_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        header("Location: schedule_interview.php?done=completed");
        exit;
    }
}

// --- Handle Cancel Interview ---
if (isset($_POST['cancel_interview'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $error_msg = "Security token mismatch. Please try again.";
    } else {
        $int_id = intval($_POST['interview_id']);
        $stmt = mysqli_prepare($con, "UPDATE interviews SET status='cancelled' WHERE id=? AND company_id=?");
        mysqli_stmt_bind_param($stmt, "ii", $int_id, $company_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        // Notify candidate about cancellation
        $cancel_q = mysqli_prepare($con, "SELECT i.user_id, i.job_id, i.interview_date, i.interview_time, cj.job_title, ui.username, ui.email
                                          FROM interviews i
                                          JOIN company_jobs cj ON i.job_id = cj.id
                                          JOIN user_info ui ON i.user_id = ui.id
                                          WHERE i.id = ? AND i.company_id = ?");
        mysqli_stmt_bind_param($cancel_q, "ii", $int_id, $company_id);
        mysqli_stmt_execute($cancel_q);
        $cancel_res = mysqli_stmt_get_result($cancel_q);
        if ($cancel_row = mysqli_fetch_assoc($cancel_res)) {
            $formatted_date = date('F j, Y', strtotime($cancel_row['interview_date']));
            $notif_title = "Interview Cancelled";
            $notif_message = "Your interview for <strong>" . htmlspecialchars($cancel_row['job_title']) . "</strong> at <strong>" . htmlspecialchars($company_name) . "</strong> scheduled for <strong>$formatted_date</strong> has been cancelled.";
            create_notification($con, 'user', $cancel_row['user_id'], 'company', $company_id, $notif_title, $notif_message, 'interview', 'interviews', $int_id);
        }
        mysqli_stmt_close($cancel_q);

        header("Location: schedule_interview.php?done=cancelled");
        exit;
    }
}

// --- Fetch application details if application_id is provided ---
$app = null;
if (isset($_GET['application_id'])) {
    $app_id = intval($_GET['application_id']);
    $app_stmt = mysqli_prepare($con, "SELECT ja.*, cj.job_title, cj.job_category, ui.username, ui.email, ui.phone
                  FROM job_applications ja
                  JOIN company_jobs cj ON ja.job_id = cj.id
                  JOIN user_info ui ON ja.user_id = ui.id
                  WHERE ja.id = ? AND ja.company_id = ?");
    mysqli_stmt_bind_param($app_stmt, "ii", $app_id, $company_id);
    mysqli_stmt_execute($app_stmt);
    $app_result = mysqli_stmt_get_result($app_stmt);
    if (mysqli_num_rows($app_result) > 0) {
        $app = mysqli_fetch_assoc($app_result);
    }
    mysqli_stmt_close($app_stmt);
}

// --- Fetch active staff for interviewer dropdown ---
$staff_list = [];
$staff_q = mysqli_prepare($con, "SELECT id, full_name AS name, email, designation, department FROM company_staff WHERE company_id = ? AND status = 'active' ORDER BY full_name ASC");
if ($staff_q) {
    mysqli_stmt_bind_param($staff_q, "i", $company_id);
    mysqli_stmt_execute($staff_q);
    $staff_res = mysqli_stmt_get_result($staff_q);
    while ($s = mysqli_fetch_assoc($staff_res)) {
        $staff_list[] = $s;
    }
    mysqli_stmt_close($staff_q);
}

// --- Handle Schedule Interview form submission ---
if (isset($_POST['schedule_interview']) && $app) {
    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $error_msg = "Security token mismatch. Please try again.";
    } else {
        $int_date     = trim($_POST['interview_date'] ?? '');
        $int_time     = trim($_POST['interview_time'] ?? '');
        $int_type     = trim($_POST['interview_type'] ?? 'Online');
        $location_val = trim($_POST['location'] ?? '');
        $meeting_link = trim($_POST['meeting_link'] ?? '');
        $notes_val    = trim($_POST['notes'] ?? '');
        $title_val    = trim($_POST['interview_title'] ?? 'Technical Interview');
        $round_num    = intval($_POST['round_number'] ?? 1);
        $duration     = intval($_POST['duration_minutes'] ?? 30);
        $interviewer_id_raw = intval($_POST['interviewer_id'] ?? 0);

        // Validation
        if (empty($int_date) || empty($int_time)) {
            $error_msg = "Please select both a date and time for the interview.";
        } elseif (!in_array($int_type, ['Online', 'Phone', 'In-Person'])) {
            $error_msg = "Invalid interview type.";
        } elseif ($int_type === 'Online' && empty($meeting_link)) {
            $error_msg = "Please provide a meeting link for online interviews.";
        } elseif ($int_type === 'Phone' && empty($location_val)) {
            $error_msg = "Please provide a phone number for phone interviews.";
        } elseif ($int_type === 'In-Person' && empty($location_val)) {
            $error_msg = "Please provide a location for in-person interviews.";
        } elseif ($duration < 15 || $duration > 180) {
            $error_msg = "Duration must be between 15 and 180 minutes.";
        } elseif ($round_num < 1 || $round_num > 10) {
            $error_msg = "Invalid round number.";
        } elseif (strlen($title_val) > 150) {
            $error_msg = "Interview title is too long (max 150 characters).";
        } else {
            // Calculate end_time
            $end_time = date('H:i:s', strtotime($int_time) + ($duration * 60));

            // Verify interviewer ownership & active status
            $interviewer_id = null;
            $interviewer_name = '';
            $interviewer_email = '';
            if ($interviewer_id_raw > 0) {
                $iv_stmt = mysqli_prepare($con, "SELECT id, full_name AS name, email FROM company_staff WHERE id = ? AND company_id = ? AND status = 'active'");
                if ($iv_stmt) {
                    mysqli_stmt_bind_param($iv_stmt, "ii", $interviewer_id_raw, $company_id);
                    mysqli_stmt_execute($iv_stmt);
                    $iv_res = mysqli_stmt_get_result($iv_stmt);
                    if ($iv_row = mysqli_fetch_assoc($iv_res)) {
                        $interviewer_id = $iv_row['id'];
                        $interviewer_name = $iv_row['name'];
                        $interviewer_email = $iv_row['email'];
                    } else {
                        $error_msg = "Selected interviewer is not valid or not active in your company.";
                    }
                    mysqli_stmt_close($iv_stmt);
                } else {
                    $error_msg = "Failed to verify interviewer.";
                }
            }

            if (empty($error_msg)) {
                // --- Interviewer Conflict Detection ---
                if ($interviewer_id) {
                    $conflict_stmt = mysqli_prepare($con, "SELECT id FROM interviews 
                        WHERE interviewer_id = ? AND interview_date = ? AND status = 'scheduled'
                        AND interview_time < ? AND end_time > ?");
                    mysqli_stmt_bind_param($conflict_stmt, "isss", $interviewer_id, $int_date, $end_time, $int_time);
                    mysqli_stmt_execute($conflict_stmt);
                    $conflict_res = mysqli_stmt_get_result($conflict_stmt);
                    if (mysqli_num_rows($conflict_res) > 0) {
                        $error_msg = "Scheduling conflict: The selected interviewer (" . htmlspecialchars($interviewer_name) . ") already has an interview scheduled during this time slot.";
                    }
                    mysqli_stmt_close($conflict_stmt);
                }
            }

            if (empty($error_msg)) {
                // --- Candidate Conflict Detection ---
                $cand_conflict = mysqli_prepare($con, "SELECT id FROM interviews 
                    WHERE user_id = ? AND interview_date = ? AND status = 'scheduled'
                    AND interview_time < ? AND end_time > ?");
                mysqli_stmt_bind_param($cand_conflict, "isss", $app['user_id'], $int_date, $end_time, $int_time);
                mysqli_stmt_execute($cand_conflict);
                $cand_res = mysqli_stmt_get_result($cand_conflict);
                if (mysqli_num_rows($cand_res) > 0) {
                    $error_msg = "Scheduling conflict: The candidate already has an interview scheduled during this time slot.";
                }
                mysqli_stmt_close($cand_conflict);
            }

            if (empty($error_msg)) {
                // --- Double-submission prevention (within 60 seconds) ---
                $dup_stmt = mysqli_prepare($con, "SELECT id FROM interviews 
                    WHERE application_id = ? AND company_id = ? AND interview_date = ? AND interview_time = ?
                    AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)");
                mysqli_stmt_bind_param($dup_stmt, "iiss", $app['id'], $company_id, $int_date, $int_time);
                mysqli_stmt_execute($dup_stmt);
                $dup_res = mysqli_stmt_get_result($dup_stmt);
                if (mysqli_num_rows($dup_res) > 0) {
                    $error_msg = "This interview appears to have been just scheduled. Please wait a moment before trying again.";
                }
                mysqli_stmt_close($dup_stmt);
            }

            if (empty($error_msg)) {
                // --- Generate Unique Access Token ---
                $access_token = bin2hex(random_bytes(32));

                // --- Insert interview record ---
                $insert_stmt = mysqli_prepare($con, "INSERT INTO interviews 
                    (application_id, company_id, user_id, job_id, interviewer_id, round_number, title, interview_date, interview_time, duration_minutes, end_time, interview_type, location, meeting_link, notes, access_token)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($insert_stmt, "iiiiiisssissssss",
                    $app['id'], $company_id, $app['user_id'], $app['job_id'],
                    $interviewer_id, $round_num, $title_val,
                    $int_date, $int_time, $duration, $end_time,
                    $int_type, $location_val, $meeting_link, $notes_val, $access_token
                );
                $insert_ok = mysqli_stmt_execute($insert_stmt);
                $new_interview_id = mysqli_insert_id($con);
                mysqli_stmt_close($insert_stmt);

                if ($insert_ok) {
                    // Update application pipeline stage
                    $upd = mysqli_prepare($con, "UPDATE job_applications SET pipeline_stage='interview', application_status='shortlisted' WHERE id=?");
                    mysqli_stmt_bind_param($upd, "i", $app['id']);
                    mysqli_stmt_execute($upd);
                    mysqli_stmt_close($upd);

                    if ($interviewer_id) {
                        $assign_stmt = mysqli_prepare($con, "INSERT INTO interview_staff_assignments (interview_id, staff_id, is_primary) VALUES (?, ?, 1)");
                        mysqli_stmt_bind_param($assign_stmt, "ii", $new_interview_id, $interviewer_id);
                        mysqli_stmt_execute($assign_stmt);
                        mysqli_stmt_close($assign_stmt);
                    }

                    // Format for notifications/emails
                    $formatted_date = date('F j, Y', strtotime($int_date));
                    $formatted_time = date('g:i A', strtotime($int_time));
                    $formatted_end  = date('g:i A', strtotime($end_time));
                    $round_label = "Round $round_num";

                    // In-app notification to candidate
                    $notif_title = "Interview Scheduled";
                    $notif_message = "Your interview for <strong>" . htmlspecialchars($app['job_title']) . "</strong> at <strong>" . htmlspecialchars($company_name) . "</strong> has been scheduled for <strong>$formatted_date</strong> at <strong>$formatted_time</strong> ($int_type).";
                    create_notification($con, 'user', $app['user_id'], 'company', $company_id, $notif_title, $notif_message, 'interview', 'interviews', $new_interview_id);

                    // Email to candidate
                    require_once __DIR__ . '/../includes/mail.php';
                    $seeker_email = get_user_email($app['user_id'], 'user');
                    if ($seeker_email && email_pref_enabled($app['user_id'], 'user', 'email_applications')) {
                        send_interview_scheduled($seeker_email, $app['username'], $app['job_title'], $company_name, $formatted_date, $formatted_time, $int_type, $meeting_link, [
                            'round' => $round_label,
                            'duration' => $duration,
                            'interviewer_name' => $interviewer_name,
                            'end_time' => $formatted_end,
                            'location' => $location_val,
                        ]);
                    }

                    // Email to interviewer
                    if ($interviewer_email) {
                        send_interviewer_assigned($interviewer_email, $interviewer_name, $app['username'], $app['job_title'], $company_name, $formatted_date, $formatted_time, $formatted_end, $duration, $round_label, $int_type, $meeting_link, $location_val, $access_token);
                    }

                    header("Location: schedule_interview.php?done=scheduled&app=" . $app['id']);
                    exit;
                } else {
                    $error_msg = "Failed to schedule the interview. Please try again.";
                }
            }
        }
    }
}

// --- Fetch all interviews for this company (with interviewer info) ---
$filter_status = $_GET['status'] ?? '';
$filter_interviewer = intval($_GET['interviewer'] ?? 0);

$interviews_sql = "SELECT i.*, ui.username, cj.job_title, cs.full_name AS interviewer_name, cs.designation AS interviewer_designation
                   FROM interviews i
                   JOIN user_info ui ON i.user_id = ui.id
                   JOIN company_jobs cj ON i.job_id = cj.id
                   LEFT JOIN company_staff cs ON i.interviewer_id = cs.id
                   WHERE i.company_id = ?";
$bind_types = "i";
$bind_vals = [$company_id];

if ($filter_status && in_array($filter_status, ['scheduled', 'completed', 'cancelled'])) {
    $interviews_sql .= " AND i.status = ?";
    $bind_types .= "s";
    $bind_vals[] = $filter_status;
}
if ($filter_interviewer > 0) {
    $interviews_sql .= " AND i.interviewer_id = ?";
    $bind_types .= "i";
    $bind_vals[] = $filter_interviewer;
}
$interviews_sql .= " ORDER BY i.interview_date DESC, i.interview_time DESC";

$int_stmt = mysqli_prepare($con, $interviews_sql);
mysqli_stmt_bind_param($int_stmt, $bind_types, ...$bind_vals);
mysqli_stmt_execute($int_stmt);
$interviews_result = mysqli_stmt_get_result($int_stmt);
$interviews = [];
while ($row = mysqli_fetch_assoc($interviews_result)) {
    $interviews[] = $row;
}
mysqli_stmt_close($int_stmt);

// Stats
$scheduled_count = 0;
$completed_count = 0;
$cancelled_count = 0;
foreach ($interviews as $int) {
    if ($int['status'] == 'scheduled') $scheduled_count++;
    elseif ($int['status'] == 'completed') $completed_count++;
    elseif ($int['status'] == 'cancelled') $cancelled_count++;
}

$type_colors = [
    'Online'    => ['#3b82f6', 'fa-video'],
    'Phone'     => ['#d97706', 'fa-phone'],
    'In-Person' => ['#059669', 'fa-building'],
];
$status_colors = [
    'scheduled' => ['#3b82f6', 'fa-calendar-check'],
    'completed' => ['#059669', 'fa-circle-check'],
    'cancelled' => ['#dc2626', 'fa-ban'],
];
$avatar_gradients = [
    ['#3b82f6', '#06b6d4'],
    ['#0ea5e9', '#06b6d4'],
    ['#059669', '#34d399'],
    ['#d97706', '#f97316'],
    ['#ec4899', '#f43f5e'],
    ['#14b8a6', '#0d9488'],
];

function si_avatar($username, $gradients) {
    $initial = strtoupper(substr(trim($username), 0, 1) ?: '?');
    $g = $gradients[abs(crc32($username)) % count($gradients)];
    return '<div class="si-avatar" style="background: linear-gradient(135deg, ' . $g[0] . ', ' . $g[1] . ');">' . $initial . '</div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Schedule Interview | Company Dashboard</title>
    <?php include '../includes/links.php'; ?>
    <style>
        :root {
            --si-bg: #f4f6fb;
            --si-card: #ffffff;
            --si-border: #e5e9f2;
            --si-text: #1e293b;
            --si-muted: #64748b;
            --si-primary: #1a56db;
            --si-primary-2: #0ea5e9;
            --si-soft: #eef2ff;
            --si-input: #f8fafc;
            --si-shadow: 0 10px 30px rgba(15, 23, 42, 0.07);
        }
        [data-theme="dark"] {
            --si-bg: #0f172a;
            --si-card: #111827;
            --si-border: #28334a;
            --si-text: #e8edff;
            --si-muted: #94a3b8;
            --si-primary: #06b6d4;
            --si-primary-2: #38bdf8;
            --si-soft: #1e293b;
            --si-input: #0d1526;
            --si-shadow: 0 10px 30px rgba(0, 0, 0, 0.45);
        }

        body {
            background:
                radial-gradient(circle at 8% 12%, rgba(99, 102, 241, 0.10), transparent 28%),
                radial-gradient(circle at 92% 8%, rgba(217, 70, 239, 0.08), transparent 26%),
                var(--si-bg);
            color: var(--si-text);
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        .si-wrap { max-width: 1200px; margin: 0 auto; padding: 34px 24px 60px; }

        /* ── Hero ── */
        .si-hero {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #1a56db 0%, #0ea5e9 55%, #38bdf8 100%);
            border-radius: 22px;
            padding: 30px 34px;
            color: #fff;
            box-shadow: 0 20px 40px rgba(79, 70, 229, 0.28);
        }
        .si-hero::before {
            content: '';
            position: absolute;
            right: -80px; top: -80px;
            width: 260px; height: 260px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.10);
        }
        .si-hero h1 { font-weight: 800; font-size: 1.75rem; color: #fff; margin: 0 0 6px; }
        .si-hero p { color: rgba(255, 255, 255, 0.85); margin: 0; font-size: 0.95rem; }
        .si-hero-btn {
            position: relative; z-index: 1;
            background: #fff; color: #1a56db;
            font-weight: 700; border: none;
            padding: 11px 22px; border-radius: 13px;
            display: inline-flex; align-items: center; gap: 8px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            transition: transform .2s ease, box-shadow .2s ease;
        }
        .si-hero-btn:hover { transform: translateY(-2px); color: #1a56db; text-decoration: none; }
        .si-hero-btn.ghost { background: rgba(255, 255, 255, 0.16); color: #fff; border: 1px solid rgba(255, 255, 255, 0.35); }
        .si-hero-btn.ghost:hover { background: #fff; color: #1a56db; }

        /* ── Stats ── */
        .si-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-top: 22px; }
        .si-stat {
            background: var(--si-card);
            border: 1px solid var(--si-border);
            border-radius: 16px;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: var(--si-shadow);
            transition: transform .2s ease, box-shadow .2s ease;
        }
        .si-stat:hover { transform: translateY(-4px); box-shadow: 0 18px 38px rgba(79, 70, 229, 0.14); }
        .si-stat-ico {
            width: 46px; height: 46px;
            border-radius: 13px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
        }
        .si-stat b { display: block; font-size: 1.45rem; line-height: 1.1; color: var(--si-text); }
        .si-stat span { font-size: 0.76rem; color: var(--si-muted); font-weight: 600; text-transform: uppercase; letter-spacing: .4px; }

        /* ── Section cards ── */
        .si-section {
            background: var(--si-card);
            border: 1px solid var(--si-border);
            border-radius: 18px;
            padding: 24px 26px;
            margin-top: 20px;
            box-shadow: var(--si-shadow);
            animation: siIn .4s ease both;
        }
        @keyframes siIn {
            from { opacity: 0; transform: translateY(14px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .si-section-head {
            display: flex; align-items: center; gap: 13px;
            margin-bottom: 18px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--si-border);
        }
        .si-section-head .ico {
            width: 42px; height: 42px;
            border-radius: 13px;
            background: linear-gradient(135deg, var(--si-primary), var(--si-primary-2));
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.05rem;
            box-shadow: 0 8px 18px rgba(79, 70, 229, 0.3);
        }
        .si-section-head h3 { font-size: 1.05rem; font-weight: 700; margin: 0; color: var(--si-text); }
        .si-section-head p { font-size: 0.8rem; color: var(--si-muted); margin: 2px 0 0; }

        /* Candidate summary */
        .si-candidate {
            display: flex; align-items: center; gap: 18px;
            background: var(--si-input);
            border: 1px solid var(--si-border);
            border-radius: 15px;
            padding: 18px 20px;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }
        .si-avatar {
            width: 52px; height: 52px;
            border-radius: 16px;
            color: #fff;
            font-weight: 800; font-size: 1.25rem;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 8px 18px rgba(0, 0, 0, 0.15);
        }
        .si-cand-main h4 { font-size: 1rem; font-weight: 700; margin: 0 0 3px; color: var(--si-text); }
        .si-cand-main p { font-size: 0.82rem; color: var(--si-muted); margin: 0; }
        .si-cand-main p i { margin-right: 5px; color: var(--si-primary); }
        .si-cand-chip {
            margin-left: auto;
            display: inline-flex; align-items: center; gap: 7px;
            font-size: 0.72rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: .4px;
            background: var(--si-soft);
            border: 1px solid var(--si-border);
            color: var(--si-primary);
            padding: 6px 14px; border-radius: 30px;
        }

        /* Form fields */
        .si-field { margin-bottom: 18px; }
        .si-label {
            font-size: 0.86rem; font-weight: 600; color: var(--si-text);
            margin-bottom: 7px;
        }
        .si-label .req { color: #dc2626; margin-left: 3px; }
        .si-label i { color: var(--si-primary); margin-right: 6px; }
        .si-input {
            width: 100%;
            background-color: var(--si-input);
            border: 1.5px solid var(--si-border);
            color: var(--si-text);
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 0.92rem;
            outline: none;
            transition: border-color .2s ease, box-shadow .2s ease, background-color .2s ease;
        }
        .si-input:focus {
            border-color: var(--si-primary);
            background-color: var(--si-card);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.14);
        }
        .si-input::placeholder { color: var(--si-muted); opacity: .7; }
        textarea.si-input { min-height: 110px; resize: vertical; line-height: 1.6; }
        select.si-input {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-color: var(--si-input);
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3e%3cpath fill='%2394a3b8' d='M1.4 0l4.6 4.6L10.6 0 12 1.4 6 7.4 0 1.4z'/%3e%3c/svg%3e");
            background-repeat: no-repeat !important;
            background-position: right 16px center !important;
            background-size: 12px 8px !important;
            padding-right: 40px;
            cursor: pointer;
        }
        [data-theme="dark"] select.si-input {
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3e%3cpath fill='%23a78bfa' d='M1.4 0l4.6 4.6L10.6 0 12 1.4 6 7.4 0 1.4z'/%3e%3c/svg%3e");
            background-repeat: no-repeat !important;
            background-position: right 16px center !important;
            background-size: 12px 8px !important;
        }
        select.si-input:focus {
            background-color: var(--si-card);
            background-repeat: no-repeat !important;
            background-position: right 16px center !important;
            background-size: 12px 8px !important;
        }
        select.si-input option {
            background-color: var(--si-card);
            color: var(--si-text);
        }

        /* Type pills */
        .si-type { display: flex; gap: 10px; flex-wrap: wrap; }
        .si-type-pill {
            flex: 1; min-width: 130px;
            border: 1.5px solid var(--si-border);
            background: var(--si-input);
            border-radius: 14px;
            padding: 15px 16px;
            cursor: pointer;
            text-align: center;
            transition: all .2s ease;
        }
        .si-type-pill i { font-size: 1.1rem; display: block; margin-bottom: 7px; color: var(--si-muted); }
        .si-type-pill b { font-size: 0.85rem; font-weight: 700; color: var(--si-text); display: block; }
        .si-type-pill input { display: none; }
        .si-type-pill.sel { border-color: var(--si-primary); background: var(--si-soft); box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.12); }
        .si-type-pill.sel i, .si-type-pill.sel b { color: var(--si-primary); }

        .si-btn {
            display: inline-flex; align-items: center; gap: 9px;
            padding: 13px 30px; border-radius: 13px;
            font-size: 0.92rem; font-weight: 700;
            border: none; cursor: pointer;
            transition: transform .2s ease, box-shadow .2s ease;
        }
        .si-btn:hover { transform: translateY(-2px); }
        .si-btn-primary {
            background: linear-gradient(135deg, var(--si-primary), var(--si-primary-2));
            color: #fff;
            box-shadow: 0 10px 22px rgba(79, 70, 229, 0.35);
        }
        .si-btn-ghost {
            background: transparent;
            border: 1.5px solid var(--si-border);
            color: var(--si-muted);
        }
        .si-btn-ghost:hover { border-color: var(--si-primary); color: var(--si-primary); }

        /* ── Interviews list ── */
        .si-list { display: flex; flex-direction: column; gap: 14px; }
        .si-card {
            background: var(--si-input);
            border: 1px solid var(--si-border);
            border-radius: 15px;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            transition: border-color .2s ease, transform .2s ease;
            animation: siIn .35s ease both;
        }
        .si-card:hover { border-color: var(--si-primary); transform: translateY(-2px); }
        .si-card-main { flex: 1; min-width: 220px; }
        .si-card-main h4 { font-size: 0.98rem; font-weight: 700; margin: 0 0 2px; color: var(--si-text); }
        .si-card-main p { font-size: 0.8rem; color: var(--si-muted); margin: 0; }
        .si-card-main p i { margin-right: 5px; color: var(--si-primary); }
        .si-when { text-align: center; flex-shrink: 0; min-width: 110px; }
        .si-when b { display: block; font-size: 1.15rem; font-weight: 800; color: var(--si-text); }
        .si-when span { font-size: 0.72rem; color: var(--si-muted); font-weight: 600; text-transform: uppercase; letter-spacing: .4px; }
        .si-badge {
            font-size: 0.7rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: .5px;
            padding: 5px 13px; border-radius: 20px;
            display: inline-flex; align-items: center; gap: 6px;
            flex-shrink: 0;
        }
        .si-badge i { font-size: 0.6rem; }
        .si-badge.scheduled { background: rgba(59, 130, 246, 0.14); color: #3b82f6; }
        .si-badge.completed { background: rgba(16, 185, 129, 0.14); color: #059669; }
        .si-badge.cancelled { background: rgba(239, 68, 68, 0.14); color: #dc2626; }
        .si-actions { display: flex; gap: 8px; flex-shrink: 0; margin-left: auto; }
        .si-act {
            display: inline-flex; align-items: center; justify-content: center; gap: 7px;
            padding: 9px 15px; border-radius: 11px;
            font-size: 0.8rem; font-weight: 600;
            border: 1.5px solid var(--si-border);
            background: var(--si-card);
            color: var(--si-text);
            text-decoration: none;
            cursor: pointer;
            transition: all .18s ease;
            white-space: nowrap;
        }
        .si-act:hover { transform: translateY(-2px); text-decoration: none; }
        .si-act-join { background: rgba(6, 182, 212, 0.12); border-color: rgba(6, 182, 212, 0.4); color: #06b6d4; }
        .si-act-join:hover { background: #06b6d4; color: #fff; }
        .si-act-complete { background: rgba(16, 185, 129, 0.12); border-color: rgba(16, 185, 129, 0.4); color: #059669; }
        .si-act-complete:hover { background: #059669; color: #fff; }
        .si-act-cancel { background: rgba(239, 68, 68, 0.12); border-color: rgba(239, 68, 68, 0.4); color: #dc2626; }
        .si-act-cancel:hover { background: #dc2626; color: #fff; }
        .si-act-detail { background: rgba(99, 102, 241, 0.10); border-color: rgba(99, 102, 241, 0.4); color: #6366f1; }
        .si-act-detail:hover { background: #6366f1; color: #fff; }

        .si-location {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 0.76rem; color: var(--si-muted);
            background: var(--si-card);
            border: 1px solid var(--si-border);
            padding: 4px 11px; border-radius: 20px;
            margin-top: 7px;
        }

        /* Round chip */
        .si-round-chip {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 0.68rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: .4px;
            background: rgba(139, 92, 246, 0.12);
            color: #8b5cf6;
            padding: 3px 10px; border-radius: 20px;
            margin-top: 4px;
        }

        /* Interviewer chip */
        .si-interviewer-chip {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 0.72rem; font-weight: 600;
            color: var(--si-muted);
            margin-top: 4px;
        }
        .si-interviewer-chip i { color: #d97706; }

        /* Filters bar */
        .si-filters {
            display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 18px;
        }
        .si-filters select {
            background: var(--si-input);
            border: 1.5px solid var(--si-border);
            color: var(--si-text);
            border-radius: 10px;
            padding: 8px 14px;
            font-size: 0.84rem;
            outline: none;
        }

        /* Empty */
        .si-empty {
            text-align: center;
            padding: 56px 24px;
            background: var(--si-input);
            border: 1.5px dashed var(--si-border);
            border-radius: 16px;
        }
        .si-empty i { font-size: 3rem; color: var(--si-primary); opacity: .35; }
        .si-empty h4 { font-weight: 700; color: var(--si-text); margin-top: 14px; }
        .si-empty p { color: var(--si-muted); }

        /* Toast */
        .si-toast {
            position: fixed; top: 84px; right: 24px; z-index: 9999;
            background: var(--si-card);
            border: 1px solid var(--si-border);
            border-left: 4px solid #059669;
            border-radius: 14px;
            padding: 15px 20px;
            display: flex; align-items: center; gap: 12px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.18);
            opacity: 0; transform: translateX(30px);
            transition: all .35s ease;
            pointer-events: none;
        }
        .si-toast.show { opacity: 1; transform: translateX(0); }
        .si-toast i { color: #059669; font-size: 1.3rem; }
        .si-toast b { color: var(--si-text); font-size: 0.9rem; }

        /* Message box for errors */
        .si-alert {
            display: flex; align-items: center; gap: 12px;
            border-radius: 13px;
            padding: 14px 18px;
            font-size: 0.9rem; font-weight: 600;
            margin-bottom: 16px;
        }
        .si-alert.error { background: rgba(239, 68, 68, 0.10); border: 1px solid rgba(239, 68, 68, 0.35); color: #dc2626; }

        /* Detail Modal */
        .si-modal-overlay {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 10000;
            background: rgba(0,0,0,0.5); backdrop-filter: blur(4px);
            align-items: center; justify-content: center;
        }
        .si-modal-overlay.active { display: flex; }
        .si-modal {
            background: var(--si-card);
            border-radius: 20px;
            padding: 28px 30px;
            width: 560px; max-width: 92vw;
            box-shadow: 0 30px 60px rgba(0,0,0,0.25);
            max-height: 80vh; overflow-y: auto;
        }
        .si-modal h3 { font-size: 1.1rem; font-weight: 800; margin: 0 0 16px; color: var(--si-text); }
        .si-modal-row { display: flex; padding: 10px 0; border-bottom: 1px solid var(--si-border); }
        .si-modal-row:last-child { border-bottom: none; }
        .si-modal-row .lbl { width: 140px; font-weight: 700; font-size: 0.82rem; color: var(--si-muted); text-transform: uppercase; letter-spacing: .3px; flex-shrink: 0; }
        .si-modal-row .val { font-size: 0.9rem; color: var(--si-text); }
        .si-modal-close {
            display: inline-flex; align-items: center; gap: 7px; margin-top: 16px;
            padding: 10px 22px; border-radius: 11px; font-weight: 700; font-size: 0.88rem;
            border: 1.5px solid var(--si-border); background: var(--si-input); color: var(--si-muted);
            cursor: pointer; transition: all .2s;
        }
        .si-modal-close:hover { border-color: var(--si-primary); color: var(--si-primary); }

        /* Conditional fields */
        .si-conditional { display: none; }
        .si-conditional.visible { display: block; }

        @media (max-width: 768px) {
            .si-wrap { padding: 22px 14px 60px; }
            .si-stats { grid-template-columns: repeat(2, 1fr); }
            .si-section { padding: 20px 18px; }
            .si-cand-chip { margin-left: 0; }
            .si-actions { width: 100%; margin-left: 0; }
            .si-actions .si-act { flex: 1; }
            .si-when { text-align: left; min-width: 0; }
        }
    </style>
</head>
<body>
    <?php include 'company_header.php'; ?>

    <div class="si-wrap">
        <!-- Hero -->
        <div class="si-hero">
            <div class="d-flex justify-content-between align-items-center flex-wrap" style="gap: 14px;">
                <div>
                    <h1><i class="fas fa-calendar-check mr-2"></i>Schedule Interview</h1>
                    <p>Manage candidate interviews for <?php echo htmlspecialchars($company_name); ?></p>
                </div>
                <div style="display:flex; gap:10px; position:relative; z-index:1;">
                    <a href="view_applicants.php" class="si-hero-btn ghost"><i class="fas fa-users"></i>Applicants</a>
                    <?php if (isset($_GET['application_id'])): ?>
                        <a href="schedule_interview.php" class="si-hero-btn"><i class="fas fa-list"></i>All Interviews</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Stats -->
        <div class="si-stats">
            <div class="si-stat">
                <div class="si-stat-ico" style="background: rgba(59,130,246,.12); color:#3b82f6;"><i class="fas fa-calendar-alt"></i></div>
                <div><b><?php echo count($interviews); ?></b><span>Total</span></div>
            </div>
            <div class="si-stat">
                <div class="si-stat-ico" style="background: rgba(59,130,246,.12); color:#3b82f6;"><i class="fas fa-hourglass-half"></i></div>
                <div><b><?php echo $scheduled_count; ?></b><span>Upcoming</span></div>
            </div>
            <div class="si-stat">
                <div class="si-stat-ico" style="background: rgba(5,150,105,.12); color:#059669;"><i class="fas fa-circle-check"></i></div>
                <div><b><?php echo $completed_count; ?></b><span>Completed</span></div>
            </div>
            <div class="si-stat">
                <div class="si-stat-ico" style="background: rgba(239,68,68,.12); color:#dc2626;"><i class="fas fa-ban"></i></div>
                <div><b><?php echo $cancelled_count; ?></b><span>Cancelled</span></div>
            </div>
        </div>

        <!-- Schedule New Interview -->
        <div class="si-section">
            <div class="si-section-head">
                <div class="ico"><i class="fas fa-plus"></i></div>
                <div>
                    <h3>Schedule New Interview</h3>
                    <p>Pick a date, time, interviewer, and format for the candidate.</p>
                </div>
            </div>

            <?php if (!empty($error_msg)): ?>
                <div class="si-alert error"><i class="fas fa-circle-exclamation"></i><?php echo $error_msg; ?></div>
            <?php endif; ?>

            <?php if ($app): ?>
                <!-- Selected applicant -->
                <div class="si-candidate">
                    <?php echo si_avatar($app['username'], $avatar_gradients); ?>
                    <div class="si-cand-main">
                        <h4><?php echo htmlspecialchars($app['username']); ?></h4>
                        <p><i class="fas fa-briefcase"></i><?php echo htmlspecialchars($app['job_title']); ?> &middot; <i class="fas fa-envelope"></i><?php echo htmlspecialchars($app['email']); ?></p>
                    </div>
                    <span class="si-cand-chip"><i class="fas fa-tag"></i><?php echo htmlspecialchars($app['job_category']); ?></span>
                </div>

                <form method="POST" action="schedule_interview.php?application_id=<?php echo $app['id']; ?>" onsubmit="return siValidate()" id="scheduleForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                    <div class="row">
                        <div class="col-md-6">
                            <div class="si-field">
                                <label class="si-label"><i class="fas fa-heading"></i>Interview Title <span class="req">*</span></label>
                                <input type="text" name="interview_title" class="si-input" required value="<?php echo htmlspecialchars($_POST['interview_title'] ?? 'Technical Interview'); ?>" maxlength="150" placeholder="e.g. Technical Screening, HR Discussion...">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="si-field">
                                <label class="si-label"><i class="fas fa-layer-group"></i>Round <span class="req">*</span></label>
                                <select name="round_number" class="si-input" required>
                                    <option value="1" <?php echo ($_POST['round_number'] ?? 1) == 1 ? 'selected' : ''; ?>>Round 1</option>
                                    <option value="2" <?php echo ($_POST['round_number'] ?? '') == 2 ? 'selected' : ''; ?>>Round 2</option>
                                    <option value="3" <?php echo ($_POST['round_number'] ?? '') == 3 ? 'selected' : ''; ?>>Round 3</option>
                                    <option value="4" <?php echo ($_POST['round_number'] ?? '') == 4 ? 'selected' : ''; ?>>Final Round</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="si-field">
                                <label class="si-label"><i class="fas fa-stopwatch"></i>Duration <span class="req">*</span></label>
                                <select name="duration_minutes" class="si-input" required id="siDuration">
                                    <option value="15" <?php echo ($_POST['duration_minutes'] ?? '') == 15 ? 'selected' : ''; ?>>15 min</option>
                                    <option value="30" <?php echo ($_POST['duration_minutes'] ?? 30) == 30 ? 'selected' : ''; ?>>30 min</option>
                                    <option value="45" <?php echo ($_POST['duration_minutes'] ?? '') == 45 ? 'selected' : ''; ?>>45 min</option>
                                    <option value="60" <?php echo ($_POST['duration_minutes'] ?? '') == 60 ? 'selected' : ''; ?>>60 min</option>
                                    <option value="90" <?php echo ($_POST['duration_minutes'] ?? '') == 90 ? 'selected' : ''; ?>>90 min</option>
                                    <option value="120" <?php echo ($_POST['duration_minutes'] ?? '') == 120 ? 'selected' : ''; ?>>120 min</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="si-field">
                                <label class="si-label"><i class="fas fa-user-tie"></i>Interviewer</label>
                                <select name="interviewer_id" class="si-input">
                                    <option value="0">— No interviewer assigned —</option>
                                    <?php foreach ($staff_list as $staff): ?>
                                        <option value="<?php echo $staff['id']; ?>" <?php echo ($_POST['interviewer_id'] ?? 0) == $staff['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($staff['name']); ?> — <?php echo htmlspecialchars($staff['designation']); ?> (<?php echo htmlspecialchars($staff['department']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($staff_list)): ?>
                                    <small style="color: var(--si-muted); font-size: 0.78rem; margin-top: 5px; display: block;">
                                        <i class="fas fa-info-circle"></i> No active staff found. <a href="manage_staff.php" style="color: var(--si-primary);">Add staff members</a> first to assign interviewers.
                                    </small>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="si-field">
                                <label class="si-label"><i class="fas fa-calendar"></i>Interview Date <span class="req">*</span></label>
                                <input type="date" name="interview_date" class="si-input" required id="siDate"
                                       min="<?php echo date('Y-m-d'); ?>"
                                       value="<?php echo htmlspecialchars($_POST['interview_date'] ?? date('Y-m-d', strtotime('+3 days'))); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="si-field">
                                <label class="si-label"><i class="fas fa-clock"></i>Start Time <span class="req">*</span></label>
                                <input type="time" name="interview_time" class="si-input" required id="siTime"
                                       value="<?php echo htmlspecialchars($_POST['interview_time'] ?? '10:00'); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="si-field">
                        <label class="si-label"><i class="fas fa-video"></i>Interview Type <span class="req">*</span></label>
                        <div class="si-type">
                            <label class="si-type-pill sel" data-type="Online">
                                <input type="radio" name="interview_type" value="Online" checked>
                                <i class="fas fa-video"></i><b>Online</b>
                            </label>
                            <label class="si-type-pill" data-type="Phone">
                                <input type="radio" name="interview_type" value="Phone">
                                <i class="fas fa-phone"></i><b>Phone</b>
                            </label>
                            <label class="si-type-pill" data-type="In-Person">
                                <input type="radio" name="interview_type" value="In-Person">
                                <i class="fas fa-building"></i><b>In-Person</b>
                            </label>
                        </div>
                    </div>

                    <!-- Conditional fields based on type -->
                    <div class="si-conditional visible" id="field-Online">
                        <div class="si-field">
                            <label class="si-label"><i class="fas fa-link"></i>Meeting Link <span class="req">*</span></label>
                            <input type="url" name="meeting_link" class="si-input" placeholder="https://meet.google.com/... or https://zoom.us/..." id="siMeetingLink"
                                   value="<?php echo htmlspecialchars($_POST['meeting_link'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="si-conditional" id="field-Phone">
                        <div class="si-field">
                            <label class="si-label"><i class="fas fa-phone-alt"></i>Phone Number <span class="req">*</span></label>
                            <input type="text" name="location" class="si-input si-loc-input" placeholder="e.g. +880 1234 567890"
                                   value="<?php echo htmlspecialchars($_POST['location'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="si-conditional" id="field-In-Person">
                        <div class="si-field">
                            <label class="si-label"><i class="fas fa-map-marker-alt"></i>Location / Address <span class="req">*</span></label>
                            <input type="text" name="location" class="si-input si-loc-input" placeholder="e.g. Office Building, 5th Floor, Dhaka"
                                   value="<?php echo htmlspecialchars($_POST['location'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="si-field">
                        <label class="si-label"><i class="fas fa-sticky-note"></i>Notes / Instructions</label>
                        <textarea name="notes" class="si-input" rows="4" placeholder="Any preparation instructions, documents to bring, interview panel details..."><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                    </div>

                    <div style="display:flex; gap:12px; flex-wrap:wrap;">
                        <button type="submit" name="schedule_interview" class="si-btn si-btn-primary">
                            <i class="fas fa-calendar-check"></i>Schedule Interview
                        </button>
                        <a href="schedule_interview.php" class="si-btn si-btn-ghost"><i class="fas fa-times"></i>Cancel</a>
                    </div>
                </form>
            <?php else: ?>
                <?php if (isset($_GET['application_id']) && !$app): ?>
                    <div class="si-alert error"><i class="fas fa-circle-exclamation"></i>Application not found or you don't have permission to view it.</div>
                <?php else: ?>
                    <div class="si-empty">
                        <i class="fas fa-user-plus d-block"></i>
                        <h4>Select a Candidate</h4>
                        <p class="mb-0">Go to your applicants list and click "Schedule Interview" next to a candidate to get started.</p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Scheduled Interviews -->
        <div class="si-section">
            <div class="si-section-head">
                <div class="ico"><i class="fas fa-calendar-alt"></i></div>
                <div>
                    <h3>Scheduled Interviews</h3>
                    <p>All interviews scheduled for your job openings.</p>
                </div>
            </div>

            <!-- Filters -->
            <div class="si-filters">
                <select id="filterStatus" onchange="applyFilters()">
                    <option value="">All Statuses</option>
                    <option value="scheduled" <?php echo $filter_status === 'scheduled' ? 'selected' : ''; ?>>Upcoming</option>
                    <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="cancelled" <?php echo $filter_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
                <?php if (!empty($staff_list)): ?>
                <select id="filterInterviewer" onchange="applyFilters()">
                    <option value="0">All Interviewers</option>
                    <?php foreach ($staff_list as $staff): ?>
                        <option value="<?php echo $staff['id']; ?>" <?php echo $filter_interviewer == $staff['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($staff['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>

            <?php if (empty($interviews)): ?>
                <div class="si-empty">
                    <i class="fas fa-calendar-times d-block"></i>
                    <h4>No Interviews Found</h4>
                    <p class="mb-0">Scheduled interviews will appear here once you start scheduling candidates.</p>
                </div>
            <?php else: ?>
                <div class="si-list">
                    <?php foreach ($interviews as $idx => $int):
                        $tcolor = $type_colors[$int['interview_type']] ?? ['#3b82f6', 'fa-video'];
                        $scolor = $status_colors[$int['status']] ?? ['#3b82f6', 'fa-calendar-check'];
                        $is_upcoming = $int['status'] == 'scheduled' && strtotime($int['interview_date'] . ' ' . $int['interview_time']) >= time();
                        $duration_display = ($int['duration_minutes'] ?? 30) . 'min';
                        $end_display = $int['end_time'] ? date('g:i A', strtotime($int['end_time'])) : '';
                    ?>
                        <div class="si-card">
                            <?php echo si_avatar($int['username'], $avatar_gradients); ?>

                            <div class="si-card-main">
                                <h4><?php echo htmlspecialchars($int['username']); ?></h4>
                                <p>
                                    <i class="fas fa-briefcase"></i><?php echo htmlspecialchars($int['job_title']); ?>
                                    &middot; <i class="fas <?php echo $tcolor[1]; ?>" style="color:<?php echo $tcolor[0]; ?>;"></i><?php echo htmlspecialchars($int['interview_type']); ?>
                                </p>
                                <?php if (!empty($int['title']) && $int['title'] !== 'Technical Interview'): ?>
                                    <span style="font-size: 0.76rem; color: var(--si-muted); font-style: italic;"><?php echo htmlspecialchars($int['title']); ?></span>
                                <?php endif; ?>
                                <?php if ($int['round_number'] > 0): ?>
                                    <span class="si-round-chip"><i class="fas fa-layer-group"></i>Round <?php echo intval($int['round_number']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($int['interviewer_name'])): ?>
                                    <span class="si-interviewer-chip"><i class="fas fa-user-tie"></i><?php echo htmlspecialchars($int['interviewer_name']); ?><?php echo !empty($int['interviewer_designation']) ? ' · ' . htmlspecialchars($int['interviewer_designation']) : ''; ?></span>
                                <?php endif; ?>
                                <?php if (!empty($int['location']) || (!empty($int['meeting_link']) && $int['interview_type'] == 'Online')): ?>
                                    <span class="si-location"><i class="fas fa-map-marker-alt"></i><?php echo htmlspecialchars($int['location'] ?: 'Meeting link provided'); ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="si-when">
                                <b><?php echo date('M d', strtotime($int['interview_date'])); ?></b>
                                <span><?php echo date('g:i A', strtotime($int['interview_time'])); ?><?php echo $end_display ? ' – ' . $end_display : ''; ?></span>
                                <span style="display:block; font-size:.66rem; color:var(--si-muted);"><?php echo $duration_display; ?></span>
                                <?php if ($is_upcoming): ?>
                                    <span style="color:#d97706; font-size:.66rem; text-transform:none; letter-spacing:0;">Upcoming</span>
                                <?php endif; ?>
                            </div>

                            <span class="si-badge <?php echo $int['status']; ?>">
                                <i class="fas <?php echo $scolor[1]; ?>"></i><?php echo ucfirst($int['status']); ?>
                            </span>

                            <div class="si-actions">
                                <button class="si-act si-act-detail" onclick="showDetail(<?php echo $idx; ?>)">
                                    <i class="fas fa-eye"></i>Details
                                </button>
                                <?php if ($int['status'] == 'scheduled'): ?>
                                    <?php if ($int['interview_type'] == 'Online' && !empty($int['meeting_link'])): ?>
                                        <a href="<?php echo htmlspecialchars($int['meeting_link']); ?>" target="_blank" rel="noopener noreferrer" class="si-act si-act-join">
                                            <i class="fas fa-video"></i>Join
                                        </a>
                                    <?php endif; ?>
                                    <form method="POST" action="schedule_interview.php" style="display:inline;" onsubmit="return confirm('Mark this interview as completed?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="interview_id" value="<?php echo $int['id']; ?>">
                                        <button type="submit" name="mark_completed" class="si-act si-act-complete">
                                            <i class="fas fa-check"></i>Complete
                                        </button>
                                    </form>
                                    <form method="POST" action="schedule_interview.php" style="display:inline;" onsubmit="return confirm('Are you sure you want to cancel this interview?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="interview_id" value="<?php echo $int['id']; ?>">
                                        <button type="submit" name="cancel_interview" class="si-act si-act-cancel">
                                            <i class="fas fa-times"></i>Cancel
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span style="font-size:.78rem; color:var(--si-muted);"><?php echo ucfirst($int['status']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Detail Modal -->
    <div class="si-modal-overlay" id="siModalOverlay" onclick="if(event.target===this)closeDetail()">
        <div class="si-modal" id="siModal"></div>
    </div>

    <!-- Toast -->
    <div class="si-toast" id="siToast"><i class="fas fa-circle-check"></i><b id="siToastMsg"></b></div>

    <script>
        // Interview data for detail modal
        const interviewsData = <?php echo json_encode(array_map(function($int) {
            return [
                'id' => $int['id'],
                'candidate' => $int['username'],
                'job' => $int['job_title'],
                'title' => $int['title'] ?? 'Technical Interview',
                'round' => $int['round_number'] ?? 1,
                'date' => date('F j, Y', strtotime($int['interview_date'])),
                'time' => date('g:i A', strtotime($int['interview_time'])),
                'end_time' => $int['end_time'] ? date('g:i A', strtotime($int['end_time'])) : '',
                'duration' => ($int['duration_minutes'] ?? 30) . ' minutes',
                'type' => $int['interview_type'],
                'status' => ucfirst($int['status']),
                'interviewer' => $int['interviewer_name'] ?? 'Not assigned',
                'interviewer_title' => $int['interviewer_designation'] ?? '',
                'location' => $int['location'] ?? '',
                'meeting_link' => $int['meeting_link'] ?? '',
                'notes' => $int['notes'] ?? '',
                'created' => date('M j, Y g:i A', strtotime($int['created_at'])),
            ];
        }, $interviews)); ?>;

        function showDetail(idx) {
            const d = interviewsData[idx];
            if (!d) return;
            let html = '<h3><i class="fas fa-calendar-check" style="color:var(--si-primary);margin-right:8px;"></i>Interview Details</h3>';
            const rows = [
                ['Candidate', d.candidate],
                ['Position', d.job],
                ['Title', d.title],
                ['Round', 'Round ' + d.round],
                ['Date', d.date],
                ['Time', d.time + (d.end_time ? ' – ' + d.end_time : '')],
                ['Duration', d.duration],
                ['Type', d.type],
                ['Status', d.status],
                ['Interviewer', d.interviewer + (d.interviewer_title ? ' · ' + d.interviewer_title : '')],
            ];
            if (d.meeting_link) rows.push(['Meeting Link', '<a href="' + d.meeting_link + '" target="_blank" rel="noopener" style="color:#3b82f6;">' + d.meeting_link + '</a>']);
            if (d.location) rows.push(['Location', d.location]);
            if (d.notes) rows.push(['Notes', d.notes]);
            rows.push(['Scheduled On', d.created]);

            rows.forEach(r => {
                html += '<div class="si-modal-row"><div class="lbl">' + r[0] + '</div><div class="val">' + r[1] + '</div></div>';
            });
            html += '<button class="si-modal-close" onclick="closeDetail()"><i class="fas fa-times"></i>Close</button>';
            document.getElementById('siModal').innerHTML = html;
            document.getElementById('siModalOverlay').classList.add('active');
        }
        function closeDetail() {
            document.getElementById('siModalOverlay').classList.remove('active');
        }
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDetail(); });

        // Toast
        function siToast(msg) {
            const t = document.getElementById('siToast');
            document.getElementById('siToastMsg').textContent = msg;
            t.classList.add('show');
            clearTimeout(window._siToastT);
            window._siToastT = setTimeout(() => t.classList.remove('show'), 4000);
        }
        <?php if (isset($_GET['done'])): ?>
            <?php if ($_GET['done'] == 'scheduled'): ?>siToast('Interview scheduled! The candidate has been notified.');<?php endif; ?>
            <?php if ($_GET['done'] == 'completed'): ?>siToast('Interview marked as completed.');<?php endif; ?>
            <?php if ($_GET['done'] == 'cancelled'): ?>siToast('Interview has been cancelled.');<?php endif; ?>
        <?php endif; ?>

        // Interview type pills + conditional fields
        document.querySelectorAll('.si-type-pill').forEach(pill => {
            pill.addEventListener('click', () => {
                document.querySelectorAll('.si-type-pill').forEach(p => p.classList.remove('sel'));
                pill.classList.add('sel');
                const type = pill.dataset.type;
                document.querySelectorAll('.si-conditional').forEach(f => f.classList.remove('visible'));
                const target = document.getElementById('field-' + type);
                if (target) target.classList.add('visible');
            });
        });

        // Filters
        function applyFilters() {
            const status = document.getElementById('filterStatus').value;
            const interviewer = document.getElementById('filterInterviewer')?.value || '0';
            let url = 'schedule_interview.php?';
            if (status) url += 'status=' + status + '&';
            if (interviewer !== '0') url += 'interviewer=' + interviewer + '&';
            window.location.href = url.replace(/&$/, '');
        }

        // Date/time validation
        function siValidate() {
            const dateVal = document.getElementById('siDate')?.value;
            if (dateVal) {
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                if (new Date(dateVal) < today) {
                    alert('Interview date cannot be in the past.');
                    return false;
                }
            }
            // Check meeting link for Online type
            const selectedType = document.querySelector('input[name="interview_type"]:checked')?.value;
            if (selectedType === 'Online') {
                const link = document.getElementById('siMeetingLink')?.value?.trim();
                if (!link) {
                    alert('Please provide a meeting link for online interviews.');
                    return false;
                }
            }
            return true;
        }
        const siDate = document.getElementById('siDate');
        if (siDate) siDate.min = new Date().toISOString().split('T')[0];
    </script>
</body>
</html>
