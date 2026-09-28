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

// Fetch interview details
$query = "
    SELECT 
        i.*,
        ui.username, ui.email, ui.profile as profile_picture,
        j.job_title,
        a.id as application_id, a.cv_type, a.cv_file, a.ai_cv_id, a.cover_letter
    FROM interviews i
    JOIN interview_staff_assignments isa ON i.id = isa.interview_id
    JOIN user_info ui ON i.user_id = ui.id
    JOIN company_jobs j ON i.job_id = j.id
    JOIN job_applications a ON i.application_id = a.id
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

// Handle Status Update
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    require_csrf();
    
    $new_status = $_POST['new_status'];
    $feedback = trim($_POST['feedback'] ?? '');

    if (in_array($new_status, ['scheduled', 'completed', 'cancelled'])) {
        $upd = mysqli_prepare($con, "UPDATE interviews SET status = ?, notes = CONCAT(IFNULL(notes,''), '\n\nStaff Feedback:\n', ?) WHERE id = ?");
        mysqli_stmt_bind_param($upd, "ssi", $new_status, $feedback, $interview_id);
        if (mysqli_stmt_execute($upd)) {
            $success_msg = "Interview status updated successfully.";
            $interview['status'] = $new_status; // update local copy
            
            // if completed, update application pipeline maybe? We leave that to company HR.
            // Notifications
            $notif_title = "Interview " . ucfirst($new_status);
            $notif_msg = "Your interview for <strong>" . htmlspecialchars($interview['job_title']) . "</strong> has been marked as $new_status.";
            create_notification($con, 'user', $interview['user_id'], 'company', $company_id, $notif_title, $notif_msg, 'interview', 'interviews', $interview_id);
        } else {
            $error_msg = "Failed to update status.";
        }
        mysqli_stmt_close($upd);
    }
}

$status_colors = [
    'scheduled' => ['#3b82f6', 'Upcoming'],
    'completed' => ['#059669', 'Completed'],
    'cancelled' => ['#dc2626', 'Cancelled']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Interview Details | NovaHire</title>
    <?php include '../includes/links.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Manrope', 'Inter', sans-serif; background: #f0f4f8; margin: 0; }
        [data-theme="dark"] body { background: #0b1120; color: #f8fafc; }
        
        .page-container {
            max-width: 1000px;
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

        .details-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
        }

        .card {
            background: #fff;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            margin-bottom: 24px;
        }
        [data-theme="dark"] .card { background: #1e293b; border: 1px solid #334155; box-shadow: none; }

        .card-title {
            margin: 0 0 20px 0;
            font-family: 'Sora', sans-serif;
            font-size: 18px;
            font-weight: 700;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 12px;
        }
        [data-theme="dark"] .card-title { border-color: #334155; }

        .info-row {
            display: flex;
            margin-bottom: 16px;
        }
        .info-label {
            flex: 0 0 120px;
            color: #64748b;
            font-weight: 600;
            font-size: 14px;
        }
        [data-theme="dark"] .info-label { color: #94a3b8; }
        .info-value {
            flex: 1;
            color: #1e293b;
            font-weight: 500;
        }
        [data-theme="dark"] .info-value { color: #f8fafc; }

        .btn {
            display: inline-block;
            padding: 10px 20px;
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

        .form-group { margin-bottom: 16px; }
        .form-label { display: block; margin-bottom: 8px; font-weight: 600; color: #475569; }
        [data-theme="dark"] .form-label { color: #cbd5e1; }
        .form-select, .form-textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-family: inherit;
            background: #fff;
            color: #1e293b;
        }
        [data-theme="dark"] .form-select, [data-theme="dark"] .form-textarea { background: #0f172a; border-color: #334155; color: #f8fafc; }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        .alert-success { background: #dcfce7; color: #166534; }
        .alert-error { background: #fee2e2; color: #991b1b; }

        @media (max-width: 768px) {
            .details-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="page-container">
    <a href="interviews.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Interviews</a>
    
    <?php if ($success_msg): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success_msg; ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error_msg; ?></div>
    <?php endif; ?>

    <div class="details-grid">
        <div class="main-col">
            <div class="card">
                <h2 class="card-title">Interview Details</h2>
                
                <div class="info-row">
                    <div class="info-label">Candidate</div>
                    <div class="info-value"><?php echo htmlspecialchars($interview['username']); ?> (<?php echo htmlspecialchars($interview['email']); ?>)</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Position</div>
                    <div class="info-value"><?php echo htmlspecialchars($interview['job_title']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Date & Time</div>
                    <div class="info-value"><?php echo date('F j, Y', strtotime($interview['interview_date'])); ?> at <?php echo date('g:i A', strtotime($interview['interview_time'])); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Type</div>
                    <div class="info-value">
                        <?php echo $interview['interview_type']; ?>
                        <?php if ($interview['interview_type'] == 'Online' && $interview['meeting_link']): ?>
                            <br><a href="<?php echo htmlspecialchars($interview['meeting_link']); ?>" target="_blank" style="color: #3b82f6; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; margin-top: 4px;"><i class="fas fa-video"></i> Join Meeting</a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Location/Phone</div>
                    <div class="info-value"><?php echo htmlspecialchars($interview['location'] ?: 'N/A'); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Status</div>
                    <div class="info-value">
                        <?php 
                        $s_col = $status_colors[$interview['status']] ?? ['#64748b', 'Unknown'];
                        echo "<span style='color: {$s_col[0]}; font-weight: 700;'>{$s_col[1]}</span>"; 
                        ?>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2 class="card-title">Notes / Instructions</h2>
                <div style="white-space: pre-wrap; line-height: 1.6; color: var(--text-color);"><?php echo htmlspecialchars($interview['notes'] ?: 'No notes provided.'); ?></div>
            </div>
        </div>

        <div class="side-col">
            <div class="card">
                <h2 class="card-title">Candidate Resources</h2>
                <!-- Resume Section -->
                <?php
                $cv_link = '';
                if (!empty($interview['cv_type'])) {
                    if ($interview['cv_type'] === 'uploaded' && !empty($interview['cv_file'])) {
                        $cv_link = '../uploads/cv_files/' . htmlspecialchars($interview['cv_file']);
                    } elseif ($interview['cv_type'] === 'ai_customized' && !empty($interview['ai_cv_id'])) {
                        $cv_link = '../seeker/view_ai_cv.php?id=' . intval($interview['ai_cv_id']);
                    } else {
                        $cv_link = '../seeker/view_cv.php?id=' . intval($interview['user_id']);
                    }
                } elseif (!empty($interview['profile_picture'])) {
                    $cv_link = '../files/' . htmlspecialchars($interview['profile_picture']); // legacy fallback
                }
                ?>
                <?php if (!empty($cv_link)): ?>
                    <a href="<?php echo htmlspecialchars($cv_link); ?>" target="_blank" class="btn btn-outline" style="width: 100%; margin-bottom: 12px;">
                        <i class="fas fa-file-pdf"></i> View Resume
                    </a>
                <?php else: ?>
                    <p style="color: #64748b; font-size: 14px;">No resume attached to application.</p>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2 class="card-title">Structured Feedback</h2>
                <p style="font-size: 14px; color: #475569; margin-bottom: 16px;">
                    Submit your formal evaluation for this candidate based on the interview.
                </p>
                <?php 
                $end_time_str = !empty($interview['end_time']) ? $interview['end_time'] : $interview['interview_time'];
                // We'll add 30 minutes to the start time if we want to be safe, but just using the time directly is fine too.
                $interview_end_timestamp = strtotime($interview['interview_date'] . ' ' . $end_time_str);
                $is_completed_or_past = ($interview['status'] === 'completed') || (time() >= $interview_end_timestamp);
                ?>
                <?php if ($is_completed_or_past): ?>
                    <a href="interview_feedback.php?id=<?php echo $interview_id; ?>" class="btn" style="width: 100%; display: block; text-align: center;">
                        <i class="fas fa-clipboard-check"></i> Submit / View Feedback
                    </a>
                <?php else: ?>
                    <button class="btn btn-outline" disabled style="width: 100%; display: block; text-align: center; cursor: not-allowed; opacity: 0.6;">
                        <i class="fas fa-clock"></i> Available After Interview
                    </button>
                    <p style="font-size: 12px; color: #64748b; margin-top: 8px; text-align: center;">You can submit feedback once the scheduled interview time has passed.</p>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2 class="card-title">Update Status</h2>
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <div class="form-group">
                        <label class="form-label">Interview Status</label>
                        <select name="new_status" class="form-select">
                            <option value="scheduled" <?php echo $interview['status'] == 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                            <option value="completed" <?php echo $interview['status'] == 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="cancelled" <?php echo $interview['status'] == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <button type="submit" name="update_status" class="btn btn-outline" style="width: 100%;">Change Status</button>
                </form>
            </div>
        </div>
    </div>
</div>

</body>
</html>
