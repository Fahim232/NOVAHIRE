<?php
/**
 * NovaHire — Candidate Selection Response Page
 *
 * After company selects (ACCEPT) a candidate, the candidate visits this page to:
 * 1. Confirm readiness to join OR decline
 * 2. Provide current employment details (company, leaving date, notice period)
 * 3. Submit approximate joining/availability date
 * 4. Add any additional notes
 *
 * Security: Anti-IDOR — verified via verify_selection_response_access()
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/hiring_workflow.php';
global $con;

// Auth check
if (!isset($_SESSION['id']) && !isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}

$user_id = intval($_SESSION['user_id'] ?? $_SESSION['id']);

// Resolve application
$app_id = intval($_GET['application_id'] ?? 0);
if ($app_id <= 0) {
    // Find latest selected application for this candidate
    $find = mysqli_query($con, "
        SELECT ja.id FROM job_applications ja
        JOIN hiring_decisions hd ON ja.id = hd.application_id
        WHERE ja.user_id = $user_id AND hd.decision = 'selected'
        ORDER BY hd.created_at DESC LIMIT 1
    ");
    if ($find && $row = mysqli_fetch_assoc($find)) {
        $app_id = intval($row['id']);
    }
}

if ($app_id <= 0) {
    header('Location: my_application.php');
    exit;
}

// Security check
$access = verify_selection_response_access($con, $app_id, $user_id);
if (!$access['allowed']) {
    header('Location: my_application.php');
    exit;
}

$app = $access['application'];
$company_name = $app['company_name'];
$job_title = $app['job_title'];
$already_responded = ($app['candidate_response_status'] ?? 'pending') !== 'pending';

// Get existing response if any
$existing_response = get_candidate_selection_response($con, $app_id);

$success_msg = '';
$error_msg = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_response'])) {
    $result = save_candidate_selection_response($con, $app_id, $user_id, $_POST);
    if ($result['success']) {
        $ready = $_POST['ready_to_join'] ?? '';
        if ($ready === 'yes') {
            $success_msg = "Your response has been submitted successfully! The company will review your details and send you an appointment letter.";
        } else {
            $success_msg = "Your response has been recorded. We wish you the best in your future endeavors.";
        }
        // Refresh data
        $access = verify_selection_response_access($con, $app_id, $user_id);
        $app = $access['application'];
        $already_responded = true;
        $existing_response = get_candidate_selection_response($con, $app_id);
    } else {
        $error_msg = $result['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Selection Response | NovaHire</title>
    <?php include __DIR__ . '/../includes/links.php'; ?>
    <style>
        :root {
            --sr-bg: #f4f6fb;
            --sr-card: #ffffff;
            --sr-border: #e5e9f2;
            --sr-text: #1e293b;
            --sr-muted: #64748b;
            --sr-primary: #6366f1;
            --sr-success: #10b981;
            --sr-danger: #ef4444;
            --sr-soft: #eef2ff;
            --sr-input: #f8fafc;
            --sr-shadow: 0 10px 30px rgba(15, 23, 42, 0.07);
        }
        [data-theme="dark"] {
            --sr-bg: #0f172a;
            --sr-card: #111827;
            --sr-border: #28334a;
            --sr-text: #e8edff;
            --sr-muted: #94a3b8;
            --sr-primary: #818cf8;
            --sr-success: #34d399;
            --sr-danger: #f87171;
            --sr-soft: #1e293b;
            --sr-input: #0d1526;
            --sr-shadow: 0 10px 30px rgba(0, 0, 0, 0.45);
        }

        body {
            background:
                radial-gradient(circle at 8% 12%, rgba(99, 102, 241, 0.10), transparent 28%),
                radial-gradient(circle at 92% 8%, rgba(16, 185, 129, 0.08), transparent 26%),
                var(--sr-bg);
            color: var(--sr-text);
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        .sr-wrap { max-width: 820px; margin: 20px auto 0; padding: 24px 24px 60px; }

        .sr-hero {
            background: linear-gradient(135deg, #6366f1 0%, #10b981 100%);
            border-radius: 22px;
            padding: 30px 34px;
            color: #fff;
            box-shadow: 0 20px 40px rgba(99, 102, 241, 0.28);
            position: relative;
            overflow: hidden;
            margin-bottom: 28px;
        }
        .sr-hero::before {
            content: '';
            position: absolute;
            right: -80px; top: -80px;
            width: 260px; height: 260px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.10);
        }
        .sr-hero h1 { font-weight: 800; font-size: 1.55rem; margin: 0 0 6px; position: relative; z-index: 1; }
        .sr-hero p { color: rgba(255, 255, 255, 0.88); margin: 0; font-size: 0.92rem; position: relative; z-index: 1; }
        .sr-hero .sr-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(255, 255, 255, 0.2); border: 1px solid rgba(255, 255, 255, 0.35);
            padding: 5px 14px; border-radius: 20px;
            font-size: 0.76rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px;
            margin-top: 12px; position: relative; z-index: 1;
        }

        .sr-card {
            background: var(--sr-card);
            border-radius: 16px;
            border: 1px solid var(--sr-border);
            padding: 28px;
            margin-bottom: 20px;
            box-shadow: var(--sr-shadow);
        }
        .sr-card h3 {
            font-size: 1.1rem;
            font-weight: 700;
            margin: 0 0 6px;
            display: flex; align-items: center; gap: 10px;
        }
        .sr-card h3 .ico {
            width: 36px; height: 36px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.9rem; color: #fff; flex-shrink: 0;
        }
        .sr-card p.sub { font-size: 0.84rem; color: var(--sr-muted); margin: 0 0 18px; }

        .sr-radio-group { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; }
        .sr-radio {
            flex: 1; min-width: 200px;
            background: var(--sr-input);
            border: 2px solid var(--sr-border);
            border-radius: 14px;
            padding: 18px;
            cursor: pointer;
            transition: all 0.25s ease;
            position: relative;
            text-align: center;
        }
        .sr-radio:hover { border-color: var(--sr-primary); }
        .sr-radio input[type="radio"] { position: absolute; opacity: 0; }
        .sr-radio.selected { border-color: var(--sr-primary); background: var(--sr-soft); box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15); }
        .sr-radio .icon { font-size: 1.8rem; margin-bottom: 8px; }
        .sr-radio b { display: block; font-size: 0.95rem; color: var(--sr-text); }
        .sr-radio span { display: block; font-size: 0.78rem; color: var(--sr-muted); margin-top: 4px; }

        .sr-field { margin-bottom: 18px; }
        .sr-field label {
            display: block;
            font-size: 0.86rem;
            font-weight: 700;
            color: var(--sr-text);
            margin-bottom: 6px;
        }
        .sr-field label .req { color: var(--sr-danger); }
        .sr-field input, .sr-field select, .sr-field textarea {
            width: 100%;
            padding: 11px 14px;
            border-radius: 10px;
            border: 1px solid var(--sr-border);
            background: var(--sr-input);
            color: var(--sr-text);
            font-size: 0.92rem;
            font-family: inherit;
            transition: border-color 0.2s;
        }
        .sr-field input:focus, .sr-field select:focus, .sr-field textarea:focus {
            outline: none;
            border-color: var(--sr-primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
        }
        .sr-field textarea { resize: vertical; min-height: 80px; }
        .sr-field .hint { font-size: 0.78rem; color: var(--sr-muted); margin-top: 4px; }

        .sr-panel {
            background: var(--sr-input);
            border: 1.5px solid var(--sr-border);
            border-radius: 12px;
            padding: 20px;
            margin-top: 16px;
            display: none;
        }
        .sr-panel.active { display: block; }
        .sr-panel h4 {
            margin: 0 0 14px;
            font-size: 0.95rem;
            display: flex; align-items: center; gap: 8px;
        }

        .sr-submit-btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 14px 32px;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 700;
            color: #fff;
            border: none;
            cursor: pointer;
            transition: all 0.25s ease;
            margin-top: 16px;
        }
        .sr-submit-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.2); }
        .sr-submit-btn.accept { background: linear-gradient(135deg, #10b981, #059669); }
        .sr-submit-btn.decline { background: linear-gradient(135deg, #ef4444, #dc2626); }

        .sr-alert {
            padding: 12px 16px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.88rem;
            margin-bottom: 18px;
            display: flex; align-items: center; gap: 10px;
        }
        .sr-alert.success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .sr-alert.error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        .sr-summary {
            display: grid; grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .sr-summary-item {
            background: var(--sr-input);
            border: 1px solid var(--sr-border);
            border-radius: 10px;
            padding: 14px;
        }
        .sr-summary-item small {
            display: block; font-size: 0.7rem; text-transform: uppercase; font-weight: 700;
            color: var(--sr-muted); letter-spacing: 0.4px; margin-bottom: 4px;
        }
        .sr-summary-item strong { font-size: 0.95rem; color: var(--sr-text); }

        @media (max-width: 600px) {
            .sr-summary { grid-template-columns: 1fr; }
            .sr-radio-group { flex-direction: column; }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="sr-wrap">
    <!-- Hero -->
    <div class="sr-hero">
        <h1><i class="fas fa-award" style="margin-right: 8px;"></i>Congratulations — You've Been Selected!</h1>
        <p><strong><?php echo htmlspecialchars($company_name); ?></strong> has selected you for the position of <strong><?php echo htmlspecialchars($job_title); ?></strong>.</p>
        <div class="sr-badge"><i class="fas fa-briefcase"></i> <?php echo htmlspecialchars($job_title); ?></div>
    </div>

    <?php if (!empty($success_msg)): ?>
        <div class="sr-alert success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>
    <?php if (!empty($error_msg)): ?>
        <div class="sr-alert error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <?php if ($existing_response && $existing_response['ready_to_join'] === 'yes' && empty($error_msg)): ?>
        <!-- Already Submitted — Summary View -->
        <div class="sr-card" style="border: 2px solid var(--sr-success);">
            <h3>
                <span class="ico" style="background: linear-gradient(135deg, #10b981, #059669);"><i class="fas fa-circle-check"></i></span>
                Response Submitted Successfully
            </h3>
            <p class="sub">The company has been notified. You will receive your appointment letter once they review your submission.</p>

            <div class="sr-summary">
                <div class="sr-summary-item">
                    <small>Ready to Join</small>
                    <strong style="color: var(--sr-success);"><i class="fas fa-check"></i> Yes — Ready</strong>
                </div>
                <div class="sr-summary-item">
                    <small>Approximate Joining Date</small>
                    <strong><?php echo date('M d, Y', strtotime($existing_response['approximate_joining_date'])); ?></strong>
                </div>
                <?php if ($existing_response['currently_working'] === 'yes'): ?>
                    <div class="sr-summary-item">
                        <small>Currently Working</small>
                        <strong>Yes<?php echo !empty($existing_response['current_company_name']) ? ' — ' . htmlspecialchars($existing_response['current_company_name']) : ''; ?></strong>
                    </div>
                    <div class="sr-summary-item">
                        <small>Expected Leaving Date</small>
                        <strong><?php echo date('M d, Y', strtotime($existing_response['expected_leaving_date'])); ?></strong>
                    </div>
                <?php else: ?>
                    <div class="sr-summary-item">
                        <small>Currently Working</small>
                        <strong>No</strong>
                    </div>
                    <div class="sr-summary-item">
                        <small>Available Immediately</small>
                        <strong><?php echo ($existing_response['available_immediately'] === 'yes') ? 'Yes' : 'No'; ?></strong>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($existing_response['candidate_notes'])): ?>
                <div style="margin-top: 14px; padding: 12px 16px; background: var(--sr-input); border-radius: 10px; border: 1px solid var(--sr-border);">
                    <small style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700; color: var(--sr-muted);">Your Notes</small>
                    <p style="margin: 4px 0 0; font-size: 0.88rem; color: var(--sr-text);"><?php echo nl2br(htmlspecialchars($existing_response['candidate_notes'])); ?></p>
                </div>
            <?php endif; ?>

            <div style="margin-top: 20px; display: flex; gap: 12px; flex-wrap: wrap;">
                <a href="hiring_onboarding.php?application_id=<?php echo $app_id; ?>" style="display: inline-flex; align-items: center; gap: 8px; padding: 11px 22px; border-radius: 10px; font-size: 0.88rem; font-weight: 700; background: var(--sr-primary); color: #fff; text-decoration: none; transition: transform 0.2s;">
                    <i class="fas fa-folder-open"></i> Upload Onboarding Documents
                </a>
                <a href="selection_response.php?application_id=<?php echo $app_id; ?>&edit=1" style="display: inline-flex; align-items: center; gap: 8px; padding: 11px 22px; border-radius: 10px; font-size: 0.88rem; font-weight: 600; background: var(--sr-input); color: var(--sr-text); text-decoration: none; border: 1px solid var(--sr-border);">
                    <i class="fas fa-pen"></i> Edit Response
                </a>
            </div>
        </div>

    <?php elseif ($existing_response && $existing_response['ready_to_join'] === 'no' && empty($error_msg)): ?>
        <!-- Declined View -->
        <div class="sr-card" style="border: 2px solid var(--sr-danger);">
            <h3>
                <span class="ico" style="background: linear-gradient(135deg, #ef4444, #dc2626);"><i class="fas fa-xmark"></i></span>
                Offer Declined
            </h3>
            <p class="sub">You have declined the offer. The company has been notified.</p>
            <div style="padding: 14px 18px; background: #fef2f2; border-radius: 10px; border: 1px solid #fecaca; color: #991b1b; font-size: 0.88rem;">
                <strong>Your reason:</strong> <?php echo htmlspecialchars($existing_response['decline_reason']); ?>
            </div>
        </div>

    <?php else: ?>
        <!-- Response Form -->
        <form method="POST" action="" id="selectionResponseForm">
            <!-- Step 1: Ready to Join? -->
            <div class="sr-card">
                <h3>
                    <span class="ico" style="background: linear-gradient(135deg, #6366f1, #8b5cf6);"><i class="fas fa-handshake"></i></span>
                    Are you ready to join?
                </h3>
                <p class="sub">Please confirm whether you would like to accept this opportunity and join <?php echo htmlspecialchars($company_name); ?>.</p>

                <div class="sr-radio-group">
                    <label class="sr-radio <?php echo ($existing_response['ready_to_join'] ?? '') === 'yes' ? 'selected' : ''; ?>" onclick="selectReady('yes')" id="radio_yes">
                        <input type="radio" name="ready_to_join" value="yes" <?php echo ($existing_response['ready_to_join'] ?? '') === 'yes' ? 'checked' : ''; ?> required>
                        <div class="icon" style="color: var(--sr-success);">✅</div>
                        <b>Yes — I'm Ready to Join</b>
                        <span>I will provide my employment details and availability</span>
                    </label>
                    <label class="sr-radio <?php echo ($existing_response['ready_to_join'] ?? '') === 'no' ? 'selected' : ''; ?>" onclick="selectReady('no')" id="radio_no">
                        <input type="radio" name="ready_to_join" value="no" <?php echo ($existing_response['ready_to_join'] ?? '') === 'no' ? 'checked' : ''; ?>>
                        <div class="icon" style="color: var(--sr-danger);">❌</div>
                        <b>No — I Decline</b>
                        <span>I am unable to accept this offer at this time</span>
                    </label>
                </div>

                <!-- Decline Reason Panel -->
                <div class="sr-panel <?php echo ($existing_response['ready_to_join'] ?? '') === 'no' ? 'active' : ''; ?>" id="decline_panel">
                    <h4 style="color: var(--sr-danger);"><i class="fas fa-comment-dots"></i> Reason for Declining</h4>
                    <div class="sr-field">
                        <label>Please provide your reason <span class="req">*</span></label>
                        <textarea name="decline_reason" placeholder="Help the company understand your decision..."><?php echo htmlspecialchars($existing_response['decline_reason'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Step 2: Employment Details (shown only when YES) -->
            <div id="employment_section" style="<?php echo ($existing_response['ready_to_join'] ?? '') === 'yes' ? '' : 'display:none;'; ?>">
                <div class="sr-card">
                    <h3>
                        <span class="ico" style="background: linear-gradient(135deg, #3b82f6, #06b6d4);"><i class="fas fa-building"></i></span>
                        Current Employment Status
                    </h3>
                    <p class="sub">Let the company know about your current employment situation.</p>

                    <div class="sr-radio-group">
                        <label class="sr-radio <?php echo ($existing_response['currently_working'] ?? '') === 'yes' ? 'selected' : ''; ?>" onclick="selectWorking('yes')" id="working_yes">
                            <input type="radio" name="currently_working" value="yes" <?php echo ($existing_response['currently_working'] ?? '') === 'yes' ? 'checked' : ''; ?>>
                            <div class="icon">🏢</div>
                            <b>Currently Working</b>
                            <span>I need to serve a notice period</span>
                        </label>
                        <label class="sr-radio <?php echo ($existing_response['currently_working'] ?? '') === 'no' ? 'selected' : ''; ?>" onclick="selectWorking('no')" id="working_no">
                            <input type="radio" name="currently_working" value="no" <?php echo ($existing_response['currently_working'] ?? '') === 'no' ? 'checked' : ''; ?>>
                            <div class="icon">🏠</div>
                            <b>Not Currently Working</b>
                            <span>I am available to start sooner</span>
                        </label>
                    </div>

                    <!-- Currently Working Details -->
                    <div class="sr-panel <?php echo ($existing_response['currently_working'] ?? '') === 'yes' ? 'active' : ''; ?>" id="working_details_panel">
                        <h4 style="color: #3b82f6;"><i class="fas fa-info-circle"></i> Employment Details</h4>
                        <div class="sr-field">
                            <label>Current Company Name</label>
                            <input type="text" name="current_company_name" placeholder="e.g. Acme Corporation" value="<?php echo htmlspecialchars($existing_response['current_company_name'] ?? ''); ?>">
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                            <div class="sr-field">
                                <label>Expected Leaving Date <span class="req">*</span></label>
                                <input type="date" name="expected_leaving_date" value="<?php echo htmlspecialchars($existing_response['expected_leaving_date'] ?? ''); ?>" min="<?php echo date('Y-m-d'); ?>">
                                <div class="hint">When do you expect to leave your current company?</div>
                            </div>
                            <div class="sr-field">
                                <label>Notice Period (Days)</label>
                                <input type="number" name="notice_period_days" placeholder="e.g. 30" min="0" max="180" value="<?php echo intval($existing_response['notice_period_days'] ?? 0); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Not Working Details -->
                    <div class="sr-panel <?php echo ($existing_response['currently_working'] ?? '') === 'no' ? 'active' : ''; ?>" id="not_working_panel">
                        <h4 style="color: #10b981;"><i class="fas fa-clock"></i> Availability</h4>
                        <div class="sr-field">
                            <label>Can you join immediately?</label>
                            <select name="available_immediately">
                                <option value="yes" <?php echo ($existing_response['available_immediately'] ?? 'yes') === 'yes' ? 'selected' : ''; ?>>Yes — Available immediately</option>
                                <option value="no" <?php echo ($existing_response['available_immediately'] ?? '') === 'no' ? 'selected' : ''; ?>>No — I need some time</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Joining Date -->
                <div class="sr-card">
                    <h3>
                        <span class="ico" style="background: linear-gradient(135deg, #10b981, #059669);"><i class="fas fa-calendar-check"></i></span>
                        Approximate Joining Date
                    </h3>
                    <p class="sub">When can you approximately start working at <?php echo htmlspecialchars($company_name); ?>?</p>

                    <div class="sr-field">
                        <label>Your Approximate Joining / Availability Date <span class="req">*</span></label>
                        <input type="date" name="approximate_joining_date" value="<?php echo htmlspecialchars($existing_response['approximate_joining_date'] ?? ''); ?>" min="<?php echo date('Y-m-d'); ?>" style="max-width: 300px;">
                        <div class="hint">The company will confirm the official joining date in your appointment letter.</div>
                    </div>
                </div>

                <!-- Step 4: Notes -->
                <div class="sr-card">
                    <h3>
                        <span class="ico" style="background: linear-gradient(135deg, #f59e0b, #d97706);"><i class="fas fa-sticky-note"></i></span>
                        Additional Notes <span style="font-size: 0.75rem; font-weight: 400; color: var(--sr-muted);">(Optional)</span>
                    </h3>
                    <div class="sr-field" style="margin-bottom: 0;">
                        <textarea name="candidate_notes" placeholder="Any additional information you'd like to share with the company..."><?php echo htmlspecialchars($existing_response['candidate_notes'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Submit -->
            <div style="text-align: center; margin-top: 10px;">
                <button type="submit" name="submit_response" class="sr-submit-btn accept" id="submit_btn">
                    <i class="fas fa-paper-plane"></i> Submit Response
                </button>
            </div>
        </form>
    <?php endif; ?>
</div>

<script>
function selectReady(val) {
    document.querySelectorAll('.sr-radio-group')[0].querySelectorAll('.sr-radio').forEach(r => r.classList.remove('selected'));
    document.getElementById('radio_' + val).classList.add('selected');

    const empSection = document.getElementById('employment_section');
    const declinePanel = document.getElementById('decline_panel');
    const submitBtn = document.getElementById('submit_btn');

    if (val === 'yes') {
        empSection.style.display = '';
        declinePanel.classList.remove('active');
        submitBtn.className = 'sr-submit-btn accept';
        submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Response';
    } else {
        empSection.style.display = 'none';
        declinePanel.classList.add('active');
        submitBtn.className = 'sr-submit-btn decline';
        submitBtn.innerHTML = '<i class="fas fa-times-circle"></i> Confirm Decline';
    }
}

function selectWorking(val) {
    const radioGroup = document.querySelectorAll('.sr-radio-group')[1];
    if (radioGroup) {
        radioGroup.querySelectorAll('.sr-radio').forEach(r => r.classList.remove('selected'));
        document.getElementById('working_' + val).classList.add('selected');
    }

    const workingPanel = document.getElementById('working_details_panel');
    const notWorkingPanel = document.getElementById('not_working_panel');

    if (val === 'yes') {
        workingPanel.classList.add('active');
        notWorkingPanel.classList.remove('active');
    } else {
        workingPanel.classList.remove('active');
        notWorkingPanel.classList.add('active');
    }
}
</script>

</body>
</html>
