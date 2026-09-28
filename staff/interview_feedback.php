<?php
require_once 'staff_header.php';
global $con;

$staff_id = $_SESSION['staff_id'];
$company_id = $_SESSION['company_id'];
$interview_id = intval($_GET['id'] ?? 0);

if (!$interview_id) {
    header("Location: interviews.php");
    exit;
}

// Check assignment
$query = "
    SELECT 
        i.*,
        ui.username,
        j.job_title
    FROM interviews i
    JOIN interview_staff_assignments isa ON i.id = isa.interview_id
    JOIN user_info ui ON i.user_id = ui.id
    JOIN company_jobs j ON i.job_id = j.id
    WHERE i.id = ? AND isa.staff_id = ?
";
$stmt = mysqli_prepare($con, $query);
mysqli_stmt_bind_param($stmt, "ii", $interview_id, $staff_id);
mysqli_stmt_execute($stmt);
$interview = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$interview) {
    echo "Interview not found or access denied.";
    exit;
}

// Time validation
$end_time_str = !empty($interview['end_time']) ? $interview['end_time'] : $interview['interview_time'];
$interview_end_timestamp = strtotime($interview['interview_date'] . ' ' . $end_time_str);
$is_completed_or_past = ($interview['status'] === 'completed') || (time() >= $interview_end_timestamp);

if (!$is_completed_or_past) {
    echo "Feedback can only be submitted after the scheduled interview time has passed.";
    exit;
}

// Fetch existing feedback
$fb_query = "SELECT * FROM interview_feedback WHERE interview_id = ? AND staff_id = ?";
$stmt = mysqli_prepare($con, $fb_query);
mysqli_stmt_bind_param($stmt, "ii", $interview_id, $staff_id);
mysqli_stmt_execute($stmt);
$existing_feedback = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

require_once '../includes/hiring_workflow.php';

$is_submitted = ($existing_feedback && $existing_feedback['status'] === 'SUBMITTED');

$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_submitted) {
    require_csrf();
    
    $tech = intval($_POST['technical_score'] ?? 0);
    $comm = intval($_POST['communication_score'] ?? 0);
    $prob = intval($_POST['problem_solving_score'] ?? 0);
    $team = intval($_POST['teamwork_score'] ?? 0);
    $prof = intval($_POST['professionalism_score'] ?? 0);
    $comments = trim($_POST['comments'] ?? '');
    $rec = $_POST['recommendation'] ?? 'FURTHER_REVIEW';
    
    $action = $_POST['action'] ?? 'DRAFT';
    $status = ($action === 'SUBMIT') ? 'SUBMITTED' : 'DRAFT';
    
    // Server-side validation
    $tech = max(1, min(10, $tech));
    $comm = max(1, min(10, $comm));
    $prob = max(1, min(10, $prob));
    $team = max(1, min(10, $team));
    $prof = max(1, min(10, $prof));
    $total_score = $tech + $comm + $prob + $team + $prof; // Max 50
    
    // Employment status validation
    $raw_emp = $_POST['employment_status'] ?? '';
    $raw_leave_date = $_POST['expected_leaving_date'] ?? null;
    $emp_val = validate_employment_feedback($raw_emp, $raw_leave_date);
    
    if (!$emp_val['valid']) {
        $error_msg = $emp_val['error'];
    } elseif (!in_array($rec, ['STRONGLY_RECOMMEND', 'RECOMMEND', 'FURTHER_REVIEW', 'NOT_RECOMMENDED'])) {
        $error_msg = "Invalid recommendation value.";
    } else {
        $emp_status = $emp_val['status'];
        $exp_date = $emp_val['leaving_date'];

        if ($existing_feedback) {
            $upd = "UPDATE interview_feedback SET technical_score=?, communication_score=?, problem_solving_score=?, teamwork_score=?, professionalism_score=?, total_score=?, comments=?, recommendation=?, employment_status=?, expected_leaving_date=?, status=? WHERE id=?";
            $stmt = mysqli_prepare($con, $upd);
            mysqli_stmt_bind_param($stmt, "iiiiiiissssi", $tech, $comm, $prob, $team, $prof, $total_score, $comments, $rec, $emp_status, $exp_date, $status, $existing_feedback['id']);
        } else {
            $ins = "INSERT INTO interview_feedback (interview_id, staff_id, technical_score, communication_score, problem_solving_score, teamwork_score, professionalism_score, total_score, comments, recommendation, employment_status, expected_leaving_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = mysqli_prepare($con, $ins);
            mysqli_stmt_bind_param($stmt, "iiiiiiiisssss", $interview_id, $staff_id, $tech, $comm, $prob, $team, $prof, $total_score, $comments, $rec, $emp_status, $exp_date, $status);
        }
        
        if (mysqli_stmt_execute($stmt)) {
            $success_msg = "Feedback saved successfully.";
            if ($status === 'SUBMITTED') {
                require_once '../includes/audit.php';
                log_hiring_audit($con, $company_id, 'staff', $staff_id, 'SUBMIT_FEEDBACK', 'interview', $interview_id, "Score: $total_score/50, Rec: $rec, Employment: $emp_status" . ($exp_date ? ", Leaving: $exp_date" : ""));
            }
            // Refresh data
            $stmt_refresh = mysqli_prepare($con, $fb_query);
            mysqli_stmt_bind_param($stmt_refresh, "ii", $interview_id, $staff_id);
            mysqli_stmt_execute($stmt_refresh);
            $existing_feedback = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_refresh));
            $is_submitted = ($existing_feedback && $existing_feedback['status'] === 'SUBMITTED');
        } else {
            $error_msg = "Error saving feedback: " . mysqli_error($con);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Interview Feedback | NovaHire</title>
    <?php include '../includes/links.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Manrope', 'Inter', sans-serif; background: #f0f4f8; margin: 0; }
        [data-theme="dark"] body { background: #0b1120; color: #f8fafc; }
        
        .page-container {
            max-width: 800px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #64748b;
            text-decoration: none;
            margin-bottom: 20px;
            font-weight: 600;
        }
        .back-link:hover { color: #3b82f6; }

        .card {
            background: #fff;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }
        [data-theme="dark"] .card { background: #1e293b; border: 1px solid #334155; box-shadow: none; }

        .card-title {
            margin: 0 0 10px 0;
            font-family: 'Sora', sans-serif;
            font-size: 20px;
            font-weight: 700;
        }

        .form-group { margin-bottom: 20px; }
        .form-label { display: block; margin-bottom: 8px; font-weight: 600; color: #475569; }
        [data-theme="dark"] .form-label { color: #cbd5e1; }
        
        .score-slider-container {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .score-slider {
            flex: 1;
            -webkit-appearance: none;
            appearance: none;
            width: 100%;
            height: 8px;
            border-radius: 4px;
            background: #e2e8f0;
            outline: none;
        }
        [data-theme="dark"] .score-slider { background: #334155; }
        .score-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #3b82f6;
            cursor: pointer;
        }
        .score-value {
            font-weight: 700;
            font-size: 16px;
            color: #1e293b;
            width: 30px;
            text-align: center;
        }
        [data-theme="dark"] .score-value { color: #f8fafc; }

        .form-select, .form-textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-family: inherit;
            background: #fff;
            color: #1e293b;
        }
        [data-theme="dark"] .form-select, [data-theme="dark"] .form-textarea { background: #0f172a; border-color: #334155; color: #f8fafc; }

        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #3b82f6;
            color: #fff;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            border: none;
            cursor: pointer;
            text-align: center;
        }
        .btn:hover { background: #2563eb; color: #fff;}
        .btn-outline {
            background: transparent;
            border: 1px solid #cbd5e1;
            color: #475569;
        }
        .btn-outline:hover { background: #f1f5f9; color: #0f172a;}
        [data-theme="dark"] .btn-outline { border-color: #475569; color: #cbd5e1;}
        [data-theme="dark"] .btn-outline:hover { background: #334155; color: #f8fafc;}

        .total-score-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
            text-align: center;
            margin: 24px 0;
        }
        [data-theme="dark"] .total-score-box { background: #0f172a; border-color: #334155; }
        .total-number {
            font-size: 32px;
            font-weight: 800;
            font-family: 'Sora', sans-serif;
            color: #3b82f6;
        }

        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-weight: 500; }
        .alert-success { background: #dcfce7; color: #166534; }
        .alert-error { background: #fee2e2; color: #991b1b; }
        .alert-warning { background: #fef08a; color: #854d0e; }

        .emp-section-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 20px;
            margin: 24px 0;
        }
        [data-theme="dark"] .emp-section-card { background: #0f172a; border-color: #334155; }
        .emp-options-grid {
            display: flex;
            gap: 14px;
            margin-top: 10px;
        }
        .emp-option-label {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 20px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            background: #fff;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        [data-theme="dark"] .emp-option-label { background: #1e293b; border-color: #334155; color: #f8fafc; }
        .emp-option-label:hover { border-color: #3b82f6; }
        .emp-option-label input[type="radio"] { width: 18px; height: 18px; accent-color: #3b82f6; cursor: pointer; }
    </style>
</head>
<body>

<div class="page-container">
    <a href="interview_details.php?id=<?php echo $interview_id; ?>" class="back-link"><i class="fas fa-arrow-left"></i> Back to Details</a>
    
    <?php if ($success_msg): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success_msg; ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error_msg; ?></div>
    <?php endif; ?>

    <div class="card">
        <h2 class="card-title">Interview Feedback</h2>
        <p style="color: #64748b; margin-bottom: 24px;">Candidate: <strong><?php echo htmlspecialchars($interview['username']); ?></strong> | Position: <strong><?php echo htmlspecialchars($interview['job_title']); ?></strong></p>

        <?php if ($is_submitted): ?>
            <div class="alert alert-success">
                <i class="fas fa-lock"></i> Feedback has been formally submitted and is locked for HR review.
            </div>
        <?php endif; ?>

        <form method="POST">
            <?php echo csrf_field(); ?>
            
            <?php 
            $categories = [
                'technical_score' => 'Technical Knowledge',
                'communication_score' => 'Communication',
                'problem_solving_score' => 'Problem Solving',
                'teamwork_score' => 'Teamwork',
                'professionalism_score' => 'Professionalism'
            ];
            foreach ($categories as $field => $label): 
                $val = $existing_feedback[$field] ?? 5;
            ?>
            <div class="form-group">
                <label class="form-label"><?php echo $label; ?> (1-10)</label>
                <div class="score-slider-container">
                    <input type="range" name="<?php echo $field; ?>" class="score-slider calc-score" min="1" max="10" value="<?php echo $val; ?>" <?php echo $is_submitted ? 'disabled' : ''; ?>>
                    <div class="score-value" id="val_<?php echo $field; ?>"><?php echo $val; ?></div>
                </div>
            </div>
            <?php endforeach; ?>

            <div class="total-score-box">
                <div style="color: #64748b; font-weight: 600; margin-bottom: 4px;">Overall Score</div>
                <div class="total-number"><span id="total_score_display"><?php echo $existing_feedback['total_score'] ?? 25; ?></span> / 50</div>
                <div style="font-size: 14px; color: #94a3b8; margin-top: 4px;" id="percentage_display">
                    <?php echo $existing_feedback ? ($existing_feedback['total_score'] * 2) : 50; ?>%
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Recommendation</label>
                <select name="recommendation" class="form-select" <?php echo $is_submitted ? 'disabled' : ''; ?>>
                    <?php 
                    $recs = [
                        'STRONGLY_RECOMMEND' => 'Strongly Recommend',
                        'RECOMMEND' => 'Recommend',
                        'FURTHER_REVIEW' => 'Needs Further Review',
                        'NOT_RECOMMENDED' => 'Not Recommended'
                    ];
                    $current_rec = $existing_feedback['recommendation'] ?? 'FURTHER_REVIEW';
                    foreach ($recs as $k => $v) {
                        $sel = ($current_rec === $k) ? 'selected' : '';
                        echo "<option value=\"$k\" $sel>$v</option>";
                    }
                    ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Comments / Justification</label>
                <textarea name="comments" class="form-textarea" rows="5" placeholder="Provide detailed feedback..." <?php echo $is_submitted ? 'disabled' : ''; ?>><?php echo htmlspecialchars($existing_feedback['comments'] ?? ''); ?></textarea>
            </div>

            <div class="emp-section-card">
                <label class="form-label" style="font-size: 15px; font-weight: 700; color: #1e293b; margin-bottom: 4px;">
                    <i class="fas fa-briefcase" style="color: #3b82f6; margin-right: 6px;"></i> EMPLOYMENT STATUS
                </label>
                <div style="font-size: 14px; color: #64748b; margin-bottom: 12px;">
                    Are you currently working at another company?
                </div>

                <div class="emp-options-grid">
                    <?php 
                    $current_emp = $existing_feedback['employment_status'] ?? '';
                    $leaving_val = $existing_feedback['expected_leaving_date'] ?? '';
                    ?>
                    <label class="emp-option-label" id="label_emp_yes">
                        <input type="radio" name="employment_status" value="CURRENTLY_WORKING" id="emp_status_yes" <?php echo ($current_emp === 'CURRENTLY_WORKING') ? 'checked' : ''; ?> <?php echo $is_submitted ? 'disabled' : ''; ?>>
                        <span>YES</span>
                    </label>
                    <label class="emp-option-label" id="label_emp_no">
                        <input type="radio" name="employment_status" value="NOT_CURRENTLY_WORKING" id="emp_status_no" <?php echo ($current_emp === 'NOT_CURRENTLY_WORKING') ? 'checked' : ''; ?> <?php echo $is_submitted ? 'disabled' : ''; ?>>
                        <span>NO</span>
                    </label>
                </div>

                <div id="expected_leaving_date_container" style="display: <?php echo ($current_emp === 'CURRENTLY_WORKING') ? 'block' : 'none'; ?>; margin-top: 16px;">
                    <label class="form-label" for="expected_leaving_date" style="font-size: 13px; font-weight: 600;">
                        Expected Leaving Date <span style="color: #ef4444;">*</span>
                    </label>
                    <input type="date" name="expected_leaving_date" id="expected_leaving_date" class="form-select" style="max-width: 280px;" value="<?php echo htmlspecialchars($leaving_val); ?>" <?php echo $is_submitted ? 'disabled' : ''; ?>>
                    <small style="display: block; color: #64748b; margin-top: 4px; font-size: 12px;">Expected final release/leaving date from current employer (YYYY-MM-DD).</small>
                </div>
            </div>

            <?php if (!$is_submitted): ?>
                <div style="display: flex; gap: 16px; margin-top: 30px;">
                    <button type="submit" name="action" value="DRAFT" class="btn btn-outline flex-fill">Save as Draft</button>
                    <button type="submit" name="action" value="SUBMIT" class="btn flex-fill" style="background: #10b981;" onclick="return confirm('Are you sure you want to submit? This action cannot be undone and will lock the feedback.');">Submit Final Feedback</button>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sliders = document.querySelectorAll('.calc-score');
    const totalDisplay = document.getElementById('total_score_display');
    const pctDisplay = document.getElementById('percentage_display');

    function calculateTotal() {
        let sum = 0;
        sliders.forEach(slider => {
            sum += parseInt(slider.value) || 0;
            document.getElementById('val_' + slider.name).textContent = slider.value;
        });
        totalDisplay.textContent = sum;
        pctDisplay.textContent = (sum * 2) + '%';
    }

    sliders.forEach(slider => {
        slider.addEventListener('input', calculateTotal);
    });

    const empYes = document.getElementById('emp_status_yes');
    const empNo = document.getElementById('emp_status_no');
    const leavingContainer = document.getElementById('expected_leaving_date_container');
    const leavingInput = document.getElementById('expected_leaving_date');

    function toggleEmploymentFields() {
        if (empYes && empYes.checked) {
            leavingContainer.style.display = 'block';
            leavingInput.setAttribute('required', 'required');
        } else {
            leavingContainer.style.display = 'none';
            leavingInput.removeAttribute('required');
            if (leavingInput && !leavingInput.disabled) {
                leavingInput.value = '';
            }
        }
    }

    if (empYes && empNo) {
        empYes.addEventListener('change', toggleEmploymentFields);
        empNo.addEventListener('change', toggleEmploymentFields);
    }
});
</script>
</body>
</html>
