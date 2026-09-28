<?php
require_once __DIR__ . '/../includes/bootstrap.php';
global $con;

$token = $_GET['token'] ?? '';
$error = '';
$success = '';

if (empty($token)) {
    die("Invalid access token.");
}

// Fetch interview details securely using the token
$interview = null;
$stmt = mysqli_prepare($con, "
    SELECT i.*, ui.username AS candidate_name, cj.job_title, cs.full_name AS interviewer_name
    FROM interviews i
    JOIN job_applications ja ON i.application_id = ja.id
    JOIN user_info ui ON ja.user_id = ui.id
    JOIN company_jobs cj ON ja.job_id = cj.id
    LEFT JOIN company_staff cs ON i.interviewer_id = cs.id
    WHERE i.access_token = ?
");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "s", $token);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $interview = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
}

if (!$interview) {
    die("Invalid or expired access token.");
}

// Check if feedback already exists
$f_stmt = mysqli_prepare($con, "SELECT id FROM interview_feedback WHERE interview_id = ?");
mysqli_stmt_bind_param($f_stmt, "i", $interview['id']);
mysqli_stmt_execute($f_stmt);
$f_res = mysqli_stmt_get_result($f_stmt);
$has_feedback = (mysqli_num_rows($f_res) > 0);
mysqli_stmt_close($f_stmt);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$has_feedback) {
    $csrf_ok = true;
    if (function_exists('verify_csrf_token')) {
        $csrf_ok = verify_csrf_token($_POST['csrf_token'] ?? '');
    } elseif (function_exists('validate_csrf')) {
        $csrf_ok = validate_csrf();
    }
    if (!$csrf_ok) {
        $error = "Security token mismatch. Please try again.";
    } else {
        $tech = intval($_POST['technical_score'] ?? 0);
        $comm = intval($_POST['communication_score'] ?? 0);
        $prob = intval($_POST['problem_solving_score'] ?? 0);
        $team = intval($_POST['teamwork_score'] ?? 0);
        $prof = intval($_POST['professionalism_score'] ?? 0);
        $comments = trim($_POST['comments'] ?? '');

        if ($tech < 1 || $tech > 10 || $comm < 1 || $comm > 10 || $prob < 1 || $prob > 10 || $team < 1 || $team > 10 || $prof < 1 || $prof > 10) {
            $error = "All scores must be between 1 and 10.";
        } else {
            require_once __DIR__ . '/../includes/hiring_workflow.php';
            $raw_emp = $_POST['employment_status'] ?? '';
            $raw_date = $_POST['expected_leaving_date'] ?? null;
            $emp_val = validate_employment_feedback($raw_emp, $raw_date);
            
            if (!$emp_val['valid']) {
                $error = $emp_val['error'];
            } else {
                $total = $tech + $comm + $prob + $team + $prof;
                $emp_status = $emp_val['status'];
                $exp_date = $emp_val['leaving_date'];
                
                mysqli_begin_transaction($con);
                try {
                    // Insert feedback
                    $ins = mysqli_prepare($con, "INSERT INTO interview_feedback (interview_id, technical_score, communication_score, problem_solving_score, teamwork_score, professionalism_score, total_score, comments, employment_status, expected_leaving_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    mysqli_stmt_bind_param($ins, "iiiiiiisss", $interview['id'], $tech, $comm, $prob, $team, $prof, $total, $comments, $emp_status, $exp_date);
                    mysqli_stmt_execute($ins);
                    mysqli_stmt_close($ins);

                    // Update interview status
                    $upd = mysqli_prepare($con, "UPDATE interviews SET status = 'completed', access_token = NULL WHERE id = ?");
                    mysqli_stmt_bind_param($upd, "i", $interview['id']);
                    mysqli_stmt_execute($upd);
                    mysqli_stmt_close($upd);

                    mysqli_commit($con);
                    $success = "Feedback submitted successfully. Thank you!";
                    $has_feedback = true;
                } catch (Exception $e) {
                    mysqli_rollback($con);
                    $error = "An error occurred while saving feedback.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Interview Feedback</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f1f5f9;
            margin: 0;
            padding: 40px 20px;
            color: #1e293b;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: #fff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        h2 { margin-top: 0; color: #0f172a; font-size: 24px; }
        .details-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 24px;
        }
        .details-card p { margin: 5px 0; font-size: 14px; }
        .form-group { margin-bottom: 16px; }
        label { display: block; font-weight: 500; margin-bottom: 6px; font-size: 14px; }
        .score-row { display: flex; align-items: center; justify-content: space-between; }
        .score-input { width: 60px; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; text-align: center; }
        textarea { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; min-height: 100px; font-family: inherit; box-sizing: border-box;}
        .btn-submit { background: #3b82f6; color: white; border: none; padding: 12px 24px; font-weight: 600; border-radius: 6px; cursor: pointer; width: 100%; font-size: 16px; }
        .btn-submit:hover { background: #2563eb; }
        .alert { padding: 12px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .info-text { font-size: 13px; color: #64748b; margin-top: -4px; margin-bottom: 12px; display: block; }
    </style>
</head>
<body>

<div class="container">
    <h2>Interview Evaluation</h2>
    
    <div class="details-card">
        <p><strong>Candidate:</strong> <?php echo htmlspecialchars($interview['candidate_name']); ?></p>
        <p><strong>Position:</strong> <?php echo htmlspecialchars($interview['job_title']); ?></p>
        <p><strong>Date & Time:</strong> <?php echo date('F j, Y', strtotime($interview['interview_date'])); ?> at <?php echo date('g:i A', strtotime($interview['interview_time'])); ?></p>
        <p><strong>Interviewer:</strong> <?php echo htmlspecialchars($interview['interviewer_name']); ?></p>
        <p><strong>Round:</strong> <?php echo htmlspecialchars($interview['round_number']); ?> - <?php echo htmlspecialchars($interview['title']); ?></p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php elseif ($has_feedback): ?>
        <div class="alert alert-success">Feedback has already been submitted for this interview.</div>
    <?php else: ?>
        
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo function_exists('csrf_token') ? csrf_token() : (function_exists('generate_csrf_token') ? generate_csrf_token() : ''); ?>">
            
            <p style="font-size: 14px; font-weight: 600; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">Rate Candidate (1 = Poor, 10 = Excellent)</p>
            
            <div class="form-group score-row">
                <label for="technical_score">Technical Skills</label>
                <input type="number" id="technical_score" name="technical_score" class="score-input" min="1" max="10" required>
            </div>
            
            <div class="form-group score-row">
                <label for="communication_score">Communication Skills</label>
                <input type="number" id="communication_score" name="communication_score" class="score-input" min="1" max="10" required>
            </div>
            
            <div class="form-group score-row">
                <label for="problem_solving_score">Problem Solving</label>
                <input type="number" id="problem_solving_score" name="problem_solving_score" class="score-input" min="1" max="10" required>
            </div>
            
            <div class="form-group score-row">
                <label for="teamwork_score">Teamwork & Collaboration</label>
                <input type="number" id="teamwork_score" name="teamwork_score" class="score-input" min="1" max="10" required>
            </div>
            
            <div class="form-group score-row">
                <label for="professionalism_score">Professionalism</label>
                <input type="number" id="professionalism_score" name="professionalism_score" class="score-input" min="1" max="10" required>
            </div>

            <div class="form-group" style="margin-top: 24px;">
                <label for="comments">Overall Comments & Notes</label>
                <span class="info-text">Internal use only. Candidate will not see these comments.</span>
                <textarea id="comments" name="comments" placeholder="Describe the candidate's strengths, weaknesses, and your overall recommendation..."></textarea>
            </div>

            <div class="form-group" style="margin-top: 24px; padding: 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                <label style="font-weight: 700; color: #1e293b; font-size: 14px;">Employment Status</label>
                <span class="info-text">Ask candidate: "Are you currently working at another company?"</span>
                <div style="display: flex; gap: 16px; margin: 10px 0;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600;">
                        <input type="radio" name="employment_status" value="CURRENTLY_WORKING" id="g_emp_yes"> YES
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600;">
                        <input type="radio" name="employment_status" value="NOT_CURRENTLY_WORKING" id="g_emp_no"> NO
                    </label>
                </div>
                <div id="g_leaving_box" style="display: none; margin-top: 10px;">
                    <label for="g_expected_leaving_date" style="font-size: 13px;">Expected Leaving Date <span style="color:#ef4444;">*</span></label>
                    <input type="date" name="expected_leaving_date" id="g_expected_leaving_date" style="padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px;">
                </div>
            </div>

            <button type="submit" class="btn-submit">Submit Feedback</button>
        </form>

        <script>
            const gYes = document.getElementById('g_emp_yes');
            const gNo = document.getElementById('g_emp_no');
            const gBox = document.getElementById('g_leaving_box');
            const gDate = document.getElementById('g_expected_leaving_date');

            function toggleGBox() {
                if (gYes && gYes.checked) {
                    gBox.style.display = 'block';
                    gDate.setAttribute('required', 'required');
                } else {
                    gBox.style.display = 'none';
                    gDate.removeAttribute('required');
                    if (gDate) gDate.value = '';
                }
            }

            if (gYes && gNo) {
                gYes.addEventListener('change', toggleGBox);
                gNo.addEventListener('change', toggleGBox);
            }
        </script>

    <?php endif; ?>
</div>

</body>
</html>
