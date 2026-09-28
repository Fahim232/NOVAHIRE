<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../ai/engine.php';
require_once __DIR__ . '/../includes/hiring_workflow.php';
global $con;

// Authentication
if (!isset($_SESSION['company_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$company_id = intval($_SESSION['company_id']);
$company_name = $_SESSION['company_name'] ?? 'Company';

// Backend verification guard
require_once __DIR__ . '/../includes/company_verification.php';
if (!is_company_verified($con, $company_id)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(403);
        die('Company verification required to issue hiring letters.');
    }
    header('Location: ' . BASE_URL . '/company/verification.php?notice=verification_required');
    exit;
}

if (!isset($_GET['application_id'])) {
    header('Location: view_applicants.php');
    exit;
}

$app_id = intval($_GET['application_id']);

// Fetch application, job, candidate, and hiring decision
$app_stmt = mysqli_prepare($con, "
    SELECT ja.*, cj.job_title, cj.job_category, cj.job_description,
           ui.username, ui.email, ui.phone,
           hd.decision, hd.final_joining_date, hd.employment_status_noted, hd.expected_leaving_date_noted
    FROM job_applications ja
    JOIN company_jobs cj ON ja.job_id = cj.id
    JOIN user_info ui ON ja.user_id = ui.id
    LEFT JOIN hiring_decisions hd ON ja.id = hd.application_id
    WHERE ja.id = ? AND ja.company_id = ?
");
mysqli_stmt_bind_param($app_stmt, "ii", $app_id, $company_id);
mysqli_stmt_execute($app_stmt);
$app = mysqli_fetch_assoc(mysqli_stmt_get_result($app_stmt));
mysqli_stmt_close($app_stmt);

if (!$app) {
    die("Application not found or unauthorized.");
}

$candidate_id = intval($app['user_id']);
$job_id = intval($app['job_id']);
$is_hired = ($app['decision'] === 'selected');

// Fetch candidate selection response
$cea = get_candidate_selection_response($con, $app_id);
$cand_ready = ($cea && $cea['ready_to_join'] === 'yes');
$cand_declined = ($cea && $cea['ready_to_join'] === 'no');

// Fetch existing hiring letter
$letter_res = mysqli_query($con, "SELECT * FROM hiring_letters WHERE application_id = $app_id AND company_id = $company_id LIMIT 1");
$hiring_letter = $letter_res && mysqli_num_rows($letter_res) > 0 ? mysqli_fetch_assoc($letter_res) : null;
$letter_id = $hiring_letter ? intval($hiring_letter['id']) : 0;
$letter_status = $hiring_letter['status'] ?? 'DRAFT';

// Fetch all versions
$versions = [];
if ($letter_id > 0) {
    $ver_res = mysqli_query($con, "SELECT * FROM appointment_letter_versions WHERE letter_id = $letter_id ORDER BY version_number DESC");
    if ($ver_res) {
        while ($v = mysqli_fetch_assoc($ver_res)) {
            $versions[] = $v;
        }
    }
}

$error = '';
$success = '';

// Handle Save Draft or Manual Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_draft'])) {
    $subject = trim($_POST['subject'] ?? "Official Appointment Letter: {$app['job_title']} — {$company_name}");
    $content = trim($_POST['content'] ?? '');
    $joining_date = trim($_POST['joining_date'] ?? ($app['final_joining_date'] ?? ($cea['approximate_joining_date'] ?? '')));
    $salary = trim($_POST['salary'] ?? '');
    $designation = trim($_POST['designation'] ?? $app['job_title']);
    $work_location = trim($_POST['work_location'] ?? '');

    if (empty($content)) {
        $error = "Appointment letter content cannot be empty.";
    } else {
        $save_res = save_appointment_letter_version(
            $con,
            $company_id,
            $candidate_id,
            $app_id,
            $job_id,
            $content,
            $subject,
            'MANUAL_EDIT',
            $joining_date,
            $salary,
            $designation,
            $work_location,
            $company_id
        );

        if ($save_res['success']) {
            if (!empty($joining_date)) {
                mysqli_query($con, "UPDATE hiring_decisions SET final_joining_date = '" . mysqli_real_escape_string($con, $joining_date) . "' WHERE application_id = $app_id");
            }
            $success = "Draft saved successfully as Version " . $save_res['version_number'] . ".";
            // Refresh
            header("Location: hiring_letter.php?application_id={$app_id}&saved=1");
            exit;
        } else {
            $error = "Failed to save draft: " . $save_res['error'];
        }
    }
}

// Handle Send Appointment Letter
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_letter'])) {
    if (!$is_hired) {
        $error = "Candidate must be formally marked as Selected (Hire) before sending an appointment letter.";
    } elseif (!$cand_ready) {
        $error = "Candidate must confirm readiness to join before an official appointment letter can be issued.";
    } elseif ($letter_id <= 0) {
        $error = "Please generate or save an appointment letter before sending.";
    } else {
        // Ensure any modified content is saved first
        $content = trim($_POST['content'] ?? '');
        $subject = trim($_POST['subject'] ?? "Official Appointment Letter: {$app['job_title']} — {$company_name}");
        $joining_date = trim($_POST['joining_date'] ?? ($app['final_joining_date'] ?? ($cea['approximate_joining_date'] ?? '')));
        $salary = trim($_POST['salary'] ?? '');
        $designation = trim($_POST['designation'] ?? $app['job_title']);
        $work_location = trim($_POST['work_location'] ?? '');

        if (!empty($content)) {
            save_appointment_letter_version(
                $con,
                $company_id,
                $candidate_id,
                $app_id,
                $job_id,
                $content,
                $subject,
                'MANUAL_EDIT',
                $joining_date,
                $salary,
                $designation,
                $work_location,
                $company_id
            );
        }

        if (!empty($joining_date)) {
            mysqli_query($con, "UPDATE hiring_decisions SET final_joining_date = '" . mysqli_real_escape_string($con, $joining_date) . "' WHERE application_id = $app_id");
        }

        $send_res = send_appointment_letter($con, $letter_id, $company_id, $company_id);
        if ($send_res['success']) {
            $success = "Official appointment letter has been approved and sent to {$app['username']}.";
            $letter_status = 'SENT';
            // Refresh data
            $letter_res = mysqli_query($con, "SELECT * FROM hiring_letters WHERE id = $letter_id");
            $hiring_letter = mysqli_fetch_assoc($letter_res);
        } else {
            $error = "Failed to send appointment letter: " . $send_res['error'];
        }
    }
}

if (isset($_GET['saved'])) {
    $success = "Draft saved successfully.";
}

// Active version to display
$selected_ver_num = intval($_GET['v'] ?? 0);
$current_content = $hiring_letter['content'] ?? '';
$current_subject = $hiring_letter['subject'] ?? "Official Appointment Letter: {$app['job_title']} — {$company_name}";
$current_joining_date = $hiring_letter['joining_date'] ?? ($app['final_joining_date'] ?? ($cea['approximate_joining_date'] ?? ''));
$current_salary = $hiring_letter['salary'] ?? '';
$current_designation = $hiring_letter['designation'] ?? $app['job_title'];
$current_location = $hiring_letter['work_location'] ?? '';

if ($selected_ver_num > 0) {
    foreach ($versions as $v) {
        if (intval($v['version_number']) === $selected_ver_num) {
            $current_content = $v['content'];
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Appointment Letter Studio | <?php echo htmlspecialchars($company_name); ?></title>
    <?php include '../includes/links.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --hl-bg: #f0f4f8;
            --hl-card: #ffffff;
            --hl-border: #e2e8f0;
            --hl-text: #1e293b;
            --hl-primary: #10b981;
        }
        [data-theme="dark"] {
            --hl-bg: #0b1120;
            --hl-card: #1e293b;
            --hl-border: #334155;
            --hl-text: #f8fafc;
        }
        body { background: var(--hl-bg); color: var(--hl-text); font-family: 'Manrope', 'Inter', sans-serif; margin: 0; }
        .hl-container { max-width: 1050px; margin: 36px auto; padding: 0 20px; }
        .back-link { display: inline-flex; align-items: center; gap: 8px; color: #64748b; text-decoration: none; margin-bottom: 20px; font-weight: 600; }
        .back-link:hover { color: #3b82f6; }

        .hl-card { background: var(--hl-card); border-radius: 14px; padding: 28px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.06); border: 1px solid var(--hl-border); margin-bottom: 24px; }
        .hl-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; padding-bottom: 18px; border-bottom: 1px solid var(--hl-border); flex-wrap: wrap; gap: 14px; }
        .hl-title h1 { margin: 0; font-family: 'Sora', sans-serif; font-size: 22px; font-weight: 800; color: var(--hl-text); }
        .hl-title p { margin: 4px 0 0; color: #64748b; font-size: 14px; }

        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 8px; font-weight: 700; font-size: 14px; border: none; cursor: pointer; transition: all 0.2s; text-decoration: none; }
        .btn-ai { background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white; box-shadow: 0 4px 14px rgba(99,102,241,0.3); }
        .btn-ai:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(99,102,241,0.4); }
        .btn-save { background: #3b82f6; color: white; }
        .btn-send { background: #10b981; color: white; box-shadow: 0 4px 14px rgba(16,185,129,0.3); }
        .btn-outline { background: transparent; border: 1px solid var(--hl-border); color: var(--hl-text); }
        .btn:hover { opacity: 0.92; }

        .grid-params { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 700; margin-bottom: 6px; font-size: 13px; color: var(--hl-text); }
        .form-input { width: 100%; padding: 10px 14px; border-radius: 8px; border: 1px solid var(--hl-border); background: var(--hl-bg); color: var(--hl-text); font-family: inherit; font-size: 14px; box-sizing: border-box; }

        .editor-area { width: 100%; min-height: 480px; padding: 20px; border-radius: 10px; border: 1.5px solid var(--hl-border); background: var(--hl-bg); color: var(--hl-text); font-family: 'Inter', monospace, sans-serif; font-size: 14px; line-height: 1.7; resize: vertical; box-sizing: border-box; white-space: pre-wrap; }

        .stream-indicator { display: none; align-items: center; gap: 8px; color: #6366f1; font-weight: 700; font-size: 14px; margin-bottom: 12px; }
        .stream-dot { width: 10px; height: 10px; border-radius: 50%; background: #6366f1; animation: pulse 1s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 0.3; transform: scale(0.9); } 50% { opacity: 1; transform: scale(1.2); } }

        .alert { padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; font-weight: 600; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        .status-badge { padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; }
        .badge-DRAFT { background: #fef3c7; color: #b45309; }
        .badge-SENT { background: #dcfce7; color: #059669; }

        .version-pill { padding: 4px 10px; border-radius: 6px; font-size: 12px; border: 1px solid var(--hl-border); text-decoration: none; color: var(--hl-text); font-weight: 600; }
        .version-pill.active { background: #6366f1; color: white; border-color: #6366f1; }
    </style>
</head>
<body>
<?php include 'company_header.php'; ?>

<div class="hl-container">
    <a href="view_applicant_detail.php?id=<?php echo $app_id; ?>" class="back-link">
        <i class="fas fa-arrow-left"></i> Back to Candidate Details
    </a>

    <?php if (!$is_hired): ?>
        <div class="alert alert-error">
            <i class="fas fa-triangle-exclamation"></i>
            <div>
                <strong>Candidate not selected for hire yet.</strong><br>
                Please finalize your hiring decision on the applicant review page before issuing an appointment letter.
            </div>
        </div>
    <?php elseif (!$cand_ready): ?>
        <div class="alert alert-error" style="background: #fef3c7; color: #92400e; border: 1px solid #fde68a;">
            <i class="fas fa-clock"></i>
            <div>
                <strong>Awaiting Candidate Selection Response.</strong><br>
                <?php if ($cand_declined): ?>
                    The candidate has declined the selection offer (Reason: <?php echo htmlspecialchars($cea['decline_reason'] ?: 'None given'); ?>). Appointment letters cannot be issued.
                <?php else: ?>
                    The candidate has been selected, but has not yet confirmed their readiness to join and availability date. You can generate and issue the appointment letter once the candidate submits their response.
                <?php endif; ?>
                <div style="margin-top: 10px; display: flex; gap: 10px;">
                    <a href="view_applicant_detail.php?id=<?php echo $app_id; ?>" class="btn btn-outline" style="font-size: 13px;">View Applicant Status</a>
                    <a href="candidate_response_review.php?application_id=<?php echo $app_id; ?>" class="btn btn-outline" style="font-size: 13px;">View Response Tracker</a>
                </div>
            </div>
        </div>
    <?php else: ?>

        <!-- Candidate Response Summary Card -->
        <div class="hl-card" style="border-left: 4px solid #10b981; background: rgba(16, 185, 129, 0.04);">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px;">
                <div>
                    <h3 style="margin: 0 0 6px; font-size: 15px; color: #059669; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-circle-check"></i> Candidate Selection Response Received
                    </h3>
                    <p style="margin: 0; font-size: 13px; color: #64748b;">
                        The candidate confirmed readiness to join on <strong><?php echo date('M d, Y', strtotime($cea['submitted_at'])); ?></strong>.
                    </p>
                </div>
                <a href="candidate_response_review.php?application_id=<?php echo $app_id; ?>" class="btn btn-outline" style="font-size: 12px; padding: 6px 14px;">
                    <i class="fas fa-user-check"></i> Full Response Details & Documents
                </a>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-top: 14px; font-size: 13px;">
                <div><strong>Candidate:</strong> <?php echo htmlspecialchars($app['username']); ?></div>
                <div><strong>Readiness:</strong> <span style="color: #10b981; font-weight: 700;">Ready to Join</span></div>
                <div><strong>Approximate Availability:</strong> <span style="color: #6366f1; font-weight: 700;"><?php echo date('M d, Y', strtotime($cea['approximate_joining_date'])); ?></span></div>
                <div><strong>Employment Status:</strong> <?php echo ($cea['currently_working'] === 'yes') ? 'Currently Working (' . htmlspecialchars($cea['current_company_name'] ?: 'N/A') . ')' : 'Immediately Available'; ?></div>
                <?php if ($cea['currently_working'] === 'yes' && !empty($cea['expected_leaving_date'])): ?>
                    <div><strong>Expected Leaving Date:</strong> <?php echo date('M d, Y', strtotime($cea['expected_leaving_date'])); ?></div>
                <?php endif; ?>
            </div>
        </div>

    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($cand_ready): ?>
    <div class="hl-card">
        <div class="hl-header">
            <div class="hl-title">
                <h1>AI Appointment Letter Studio</h1>
                <p>
                    Candidate: <strong><?php echo htmlspecialchars($app['username']); ?></strong> &middot; 
                    Position: <strong><?php echo htmlspecialchars($app['job_title']); ?></strong>
                    <?php if (!empty($app['final_joining_date'])): ?>
                        &middot; Confirmed Joining: <strong style="color: #10b981;"><?php echo date('M d, Y', strtotime($app['final_joining_date'])); ?></strong>
                    <?php endif; ?>
                </p>
            </div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <span class="status-badge badge-<?php echo $letter_status; ?>">
                    <?php echo ($letter_status === 'SENT') ? 'SENT TO CANDIDATE' : 'DRAFT IN PROGRESS'; ?>
                </span>
            </div>
        </div>

        <!-- Appointment Parameter Form (AI Context Grounds) -->
        <form method="POST" id="appointment_form">
            <div style="background: rgba(99, 102, 241, 0.04); border: 1px solid rgba(99, 102, 241, 0.2); border-radius: 10px; padding: 18px; margin-bottom: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <b style="font-size: 14px; color: var(--hl-text);"><i class="fas fa-sliders" style="color: #6366f1; margin-right: 6px;"></i> Verified Employment Contract Terms</b>
                    <small style="color: #64748b;">AI receives only verified fields. Non-specified fields are excluded.</small>
                </div>

                <div class="grid-params">
                    <div class="form-group">
                        <label>Designation / Title *</label>
                        <input type="text" name="designation" id="designation" class="form-input" value="<?php echo htmlspecialchars($current_designation); ?>" required <?php echo $letter_status === 'SENT' ? 'disabled' : ''; ?>>
                    </div>
                    <div class="form-group">
                        <label>Confirmed Joining Date *</label>
                        <input type="date" name="joining_date" id="joining_date" class="form-input" value="<?php echo htmlspecialchars($current_joining_date); ?>" required <?php echo $letter_status === 'SENT' ? 'disabled' : ''; ?>>
                    </div>
                    <div class="form-group">
                        <label>Remuneration / Salary (Optional)</label>
                        <input type="text" name="salary" id="salary" class="form-input" placeholder="e.g. $85,000 / annum or As Discussed" value="<?php echo htmlspecialchars($current_salary); ?>" <?php echo $letter_status === 'SENT' ? 'disabled' : ''; ?>>
                    </div>
                    <div class="form-group">
                        <label>Work Location (Optional)</label>
                        <input type="text" name="work_location" id="work_location" class="form-input" placeholder="e.g. Headquarters, New York / Hybrid" value="<?php echo htmlspecialchars($current_location); ?>" <?php echo $letter_status === 'SENT' ? 'disabled' : ''; ?>>
                    </div>
                    <div class="form-group">
                        <label>Reporting Manager (Optional)</label>
                        <input type="text" name="reporting_manager" id="reporting_manager" class="form-input" placeholder="e.g. Engineering Director" <?php echo $letter_status === 'SENT' ? 'disabled' : ''; ?>>
                    </div>
                    <div class="form-group">
                        <label>Probation Period (Optional)</label>
                        <input type="text" name="probation_period" id="probation_period" class="form-input" placeholder="e.g. 3 Months" <?php echo $letter_status === 'SENT' ? 'disabled' : ''; ?>>
                    </div>
                </div>

                <?php if ($letter_status !== 'SENT'): ?>
                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" id="btn_generate_ai" class="btn btn-ai" <?php echo !$is_hired ? 'disabled' : ''; ?>>
                        <i class="fas fa-wand-magic-sparkles"></i> Generate Appointment Letter with AI
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <!-- Version History Tabs -->
            <?php if (!empty($versions)): ?>
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 14px; flex-wrap: wrap;">
                    <span style="font-size: 13px; font-weight: 700; color: #64748b; margin-right: 6px;"><i class="fas fa-clock-rotate-left"></i> Versions:</span>
                    <?php foreach ($versions as $ver): 
                        $is_active = ($selected_ver_num === intval($ver['version_number'])) || ($selected_ver_num === 0 && $ver['id'] == ($hiring_letter['current_version_id'] ?? 0));
                    ?>
                        <a href="hiring_letter.php?application_id=<?php echo $app_id; ?>&v=<?php echo $ver['version_number']; ?>" class="version-pill <?php echo $is_active ? 'active' : ''; ?>">
                            v<?php echo $ver['version_number']; ?> (<?php echo htmlspecialchars($ver['generation_method']); ?> - <?php echo date('M d, g:i A', strtotime($ver['created_at'])); ?>)
                            <?php if ($ver['status'] === 'SENT'): ?><span style="color:#10b981;">&#10003; SENT</span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Subject Line -->
            <div class="form-group" style="margin-bottom: 16px;">
                <label>Subject Line</label>
                <input type="text" name="subject" id="subject" class="form-input" value="<?php echo htmlspecialchars($current_subject); ?>" required <?php echo $letter_status === 'SENT' ? 'readonly' : ''; ?>>
            </div>

            <!-- Live Streaming Indicator -->
            <div class="stream-indicator" id="stream_indicator">
                <div class="stream-dot"></div>
                <span id="stream_text">Generating appointment letter in real-time...</span>
            </div>

            <!-- Editor / Letter Output -->
            <div class="form-group" style="margin-bottom: 24px;">
                <label style="display: flex; justify-content: space-between; align-items: center;">
                    <span>Official Appointment Letter Content</span>
                    <?php if ($letter_status !== 'SENT'): ?>
                        <small style="color: #64748b; font-weight: 500;">You can freely edit and customize this text before sending.</small>
                    <?php endif; ?>
                </label>
                <textarea name="content" id="letter_content" class="editor-area" required placeholder="Click 'Generate Appointment Letter with AI' above or compose your letter here..." <?php echo $letter_status === 'SENT' ? 'readonly' : ''; ?>><?php echo htmlspecialchars($current_content); ?></textarea>
            </div>

            <!-- Actions Bar -->
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                <div>
                    <a href="view_applicant_detail.php?id=<?php echo $app_id; ?>" class="btn btn-outline">
                        Cancel / Back
                    </a>
                </div>

                <div style="display: flex; gap: 10px;">
                    <?php if ($letter_status !== 'SENT'): ?>
                        <button type="submit" name="save_draft" class="btn btn-save">
                            <i class="fas fa-save"></i> Save Draft Version
                        </button>
                        <button type="submit" name="send_letter" class="btn btn-send" onclick="return confirm('Are you sure you want to approve and send this official appointment letter to <?php echo htmlspecialchars(addslashes($app['username'])); ?>? They will be notified immediately.');">
                            <i class="fas fa-paper-plane"></i> Send Official Appointment Letter
                        </button>
                    <?php else: ?>
                        <button type="button" class="btn btn-save" onclick="window.print();">
                            <i class="fas fa-print"></i> Print / Download PDF
                        </button>
                    <?php endif; ?>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const btnGen = document.getElementById('btn_generate_ai');
    const contentBox = document.getElementById('letter_content');
    const streamIndicator = document.getElementById('stream_indicator');
    const streamText = document.getElementById('stream_text');

    if (!btnGen) return;

    btnGen.addEventListener('click', async function() {
        const designation = document.getElementById('designation').value.trim();
        const joiningDate = document.getElementById('joining_date').value.trim();
        const salary = document.getElementById('salary').value.trim();
        const workLocation = document.getElementById('work_location').value.trim();
        const manager = document.getElementById('reporting_manager').value.trim();
        const probation = document.getElementById('probation_period').value.trim();

        if (!joiningDate) {
            alert('Please specify a confirmed joining date before generating.');
            return;
        }

        if (contentBox.value.trim() !== '' && !confirm('Generating a new draft with AI will replace the current editor view. Continue?')) {
            return;
        }

        btnGen.disabled = true;
        btnGen.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
        streamIndicator.style.display = 'flex';
        streamText.textContent = 'Generating appointment letter in real-time...';
        contentBox.value = '';

        try {
            const params = new URLSearchParams({
                application_id: '<?php echo $app_id; ?>',
                designation: designation,
                joining_date: joiningDate,
                salary: salary,
                work_location: workLocation,
                reporting_manager: manager,
                probation_period: probation
            });

            const response = await fetch('../api/stream_appointment_letter.php?' + params.toString());
            const reader = response.body.getReader();
            const decoder = new TextDecoder('utf-8');

            let accumulated = '';

            while (true) {
                const { value, done } = await reader.read();
                if (done) break;

                const textChunk = decoder.decode(value, { stream: true });
                const lines = textChunk.split('\n');

                for (let line of lines) {
                    line = line.trim();
                    if (line.startsWith('data: ')) {
                        const jsonStr = line.substring(6);
                        try {
                            const data = JSON.parse(jsonStr);
                            if (data.token) {
                                accumulated += data.token;
                                contentBox.value = accumulated;
                                contentBox.scrollTop = contentBox.scrollHeight;
                            }
                            if (data.done) {
                                streamText.textContent = 'Letter generated successfully!';
                                setTimeout(() => { streamIndicator.style.display = 'none'; }, 2000);
                            }
                            if (data.error) {
                                alert('AI Generation Alert: ' + data.error);
                            }
                        } catch (e) {
                            // parse error / partial JSON chunk
                        }
                    }
                }
            }
        } catch (err) {
            alert('Failed to connect to AI streaming engine: ' + err.message);
            streamIndicator.style.display = 'none';
        } finally {
            btnGen.disabled = false;
            btnGen.innerHTML = '<i class="fas fa-wand-magic-sparkles"></i> Regenerate with AI';
        }
    });
});
</script>
</body>
</html>
