<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/hiring_workflow.php';
global $con;

if (!isset($_SESSION['id']) && !isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}

$user_id = intval($_SESSION['user_id'] ?? $_SESSION['id']);

$app_id = intval($_GET['application_id'] ?? 0);
if ($app_id > 0) {
    $stmt = mysqli_prepare($con, "
        SELECT ja.*, cj.job_title, c.company_name, c.logo AS company_logo,
               hd.decision, hd.final_joining_date, hd.employment_status_noted,
               hl.id AS hiring_letter_id, hl.status AS letter_status
        FROM job_applications ja
        JOIN company_jobs cj ON ja.job_id = cj.id
        JOIN companies c ON ja.company_id = c.id
        LEFT JOIN hiring_decisions hd ON ja.id = hd.application_id
        LEFT JOIN hiring_letters hl ON ja.id = hl.application_id AND hl.status = 'SENT'
        WHERE ja.id = ? AND ja.user_id = ?
    ");
    mysqli_stmt_bind_param($stmt, "ii", $app_id, $user_id);
    mysqli_stmt_execute($stmt);
    $app = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
} else {
    // Locate latest active hired application
    $res = mysqli_query($con, "
        SELECT ja.*, cj.job_title, c.company_name, c.logo AS company_logo,
               hd.decision, hd.final_joining_date, hd.employment_status_noted,
               hl.id AS hiring_letter_id, hl.status AS letter_status
        FROM job_applications ja
        JOIN company_jobs cj ON ja.job_id = cj.id
        JOIN companies c ON ja.company_id = c.id
        LEFT JOIN hiring_decisions hd ON ja.id = hd.application_id
        LEFT JOIN hiring_letters hl ON ja.id = hl.application_id AND hl.status = 'SENT'
        WHERE ja.user_id = $user_id AND (hd.decision = 'selected' OR ja.application_status = 'selected' OR ja.pipeline_stage IN ('offered', 'hired'))
        ORDER BY ja.id DESC LIMIT 1
    ");
    $app = $res ? mysqli_fetch_assoc($res) : null;
}

if (!$app) {
    header('Location: my_application.php');
    exit;
}

if (($app['decision'] ?? '') !== 'selected' && ($app['application_status'] ?? '') !== 'selected') {
    header('Location: my_application.php');
    exit;
}

// Enforce candidate response exists and ready_to_join is yes
$cea = get_candidate_selection_response($con, intval($app['id']));
if (!$cea || $cea['ready_to_join'] !== 'yes') {
    header('Location: selection_response.php?application_id=' . intval($app['id']));
    exit;
}

$app_id = intval($app['id']);
$company_id = intval($app['company_id']);
$company_name = $app['company_name'];

$success_msg = '';
$error_msg = '';

// Handle Document Upload
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_doc') {
    $req_id = intval($_POST['requirement_id'] ?? 0);
    
    // Verify requirement belongs to this application
    $chk_req = mysqli_prepare($con, "SELECT * FROM hiring_document_requirements WHERE id = ? AND application_id = ?");
    mysqli_stmt_bind_param($chk_req, "ii", $req_id, $app_id);
    mysqli_stmt_execute($chk_req);
    $req_data = mysqli_fetch_assoc(mysqli_stmt_get_result($chk_req));
    mysqli_stmt_close($chk_req);

    if (!$req_data) {
        $error_msg = "Invalid requirement selected.";
    } elseif (!isset($_FILES['doc_file']) || $_FILES['doc_file']['error'] !== UPLOAD_ERR_OK) {
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE   => "File exceeds server max upload size.",
            UPLOAD_ERR_FORM_SIZE  => "File exceeds allowed form size.",
            UPLOAD_ERR_PARTIAL    => "File upload was interrupted.",
            UPLOAD_ERR_NO_FILE    => "No file was selected for upload."
        ];
        $error_msg = $upload_errors[$_FILES['doc_file']['error']] ?? "Failed to upload document.";
    } else {
        $file = $_FILES['doc_file'];
        $orig_name = basename($file['name']);
        $file_size = $file['size'];
        $tmp_name = $file['tmp_name'];

        $allowed_exts = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed_exts)) {
            $error_msg = "Invalid file type. Allowed formats: PDF, JPG, PNG, WEBP.";
        } elseif ($file_size > 10 * 1024 * 1024) { // 10MB limit
            $error_msg = "File size exceeds the 10MB limit.";
        } else {
            // Determine MIME type securely
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $tmp_name);
            finfo_close($finfo);

            $allowed_mimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
            if (!in_array($mime, $allowed_mimes)) {
                $error_msg = "Uploaded file does not match allowed document MIME types.";
            } else {
                $storage_dir = __DIR__ . '/../uploads/hiring_documents';
                if (!is_dir($storage_dir)) {
                    mkdir($storage_dir, 0755, true);
                    file_put_contents($storage_dir . '/.htaccess', "Deny from all\n");
                }

                $unique_name = 'doc_' . $app_id . '_' . $req_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                $dest_path = $storage_dir . '/' . $unique_name;

                if (move_uploaded_file($tmp_name, $dest_path)) {
                    // Check if previous document exists for this requirement
                    $chk_prev = mysqli_query($con, "SELECT id FROM candidate_documents WHERE requirement_id = $req_id AND application_id = $app_id LIMIT 1");
                    if ($chk_prev && mysqli_num_rows($chk_prev) > 0) {
                        $prev_id = mysqli_fetch_assoc($chk_prev)['id'];
                        $upd_stmt = mysqli_prepare($con, "
                            UPDATE candidate_documents 
                            SET file_path = ?, original_filename = ?, mime_type = ?, file_size = ?, status = 'UPLOADED', rejection_reason = NULL, uploaded_at = NOW()
                            WHERE id = ?
                        ");
                        mysqli_stmt_bind_param($upd_stmt, "sssii", $unique_name, $orig_name, $mime, $file_size, $prev_id);
                        mysqli_stmt_execute($upd_stmt);
                        mysqli_stmt_close($upd_stmt);
                    } else {
                        $ins_stmt = mysqli_prepare($con, "
                            INSERT INTO candidate_documents (company_id, application_id, candidate_id, requirement_id, file_path, original_filename, mime_type, file_size, status)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'UPLOADED')
                        ");
                        mysqli_stmt_bind_param($ins_stmt, "iiiisssi", $company_id, $app_id, $user_id, $req_id, $unique_name, $orig_name, $mime, $file_size);
                        mysqli_stmt_execute($ins_stmt);
                        mysqli_stmt_close($ins_stmt);
                    }

                    // Update requirement status
                    mysqli_query($con, "UPDATE hiring_document_requirements SET status = 'UPLOADED' WHERE id = $req_id");

                    // Audit log & Notify company
                    require_once __DIR__ . '/../includes/audit.php';
                    log_hiring_audit($con, $company_id, 'candidate', $user_id, 'DOCUMENT_UPLOADED', 'candidate_document', $req_id, "Uploaded: $orig_name for '{$req_data['title']}'");

                    require_once __DIR__ . '/../includes/functions.php';
                    create_notification(
                        $con,
                        'company',
                        $company_id,
                        'user',
                        $user_id,
                        "Candidate Document Uploaded",
                        "Candidate uploaded {$req_data['title']} for review.",
                        'document_uploaded',
                        'job_application',
                        $app_id
                    );

                    $success_msg = "Document '{$req_data['title']}' uploaded successfully!";
                } else {
                    $error_msg = "Could not save uploaded file to storage.";
                }
            }
        }
    }
}

// Fetch all requirements and candidate's uploaded files
$reqs = [];
$req_res = mysqli_query($con, "SELECT * FROM hiring_document_requirements WHERE application_id = $app_id ORDER BY id ASC");
if ($req_res) {
    while ($r = mysqli_fetch_assoc($req_res)) {
        $reqs[$r['id']] = $r;
        $reqs[$r['id']]['uploaded_doc'] = null;
    }
}

// If candidate is hired/selected, but no requirements exist yet in database, auto-seed standard required documents:
if (empty($reqs) && ($app['application_status'] === 'selected' || ($app['decision'] ?? '') === 'selected' || in_array($app['pipeline_stage'] ?? '', ['offered', 'hired']))) {
    $default_req_items = [
        ['document_type' => 'NID', 'title' => 'National Identity Card (NID)', 'description' => 'Government-issued National ID Card or Smart Card.'],
        ['document_type' => 'Educational Certificate', 'title' => 'Educational Certificate', 'description' => 'Official certificate of your highest educational degree.'],
        ['document_type' => 'Academic Transcript', 'title' => 'Academic Transcript', 'description' => 'Official university/board academic transcript or mark sheet.']
    ];
    if (($app['employment_status_noted'] ?? '') === 'CURRENTLY_WORKING') {
        $default_req_items[] = ['document_type' => 'Employment Certificate', 'title' => 'Relieving Letter / Experience Certificate', 'description' => 'Release letter or proof of clearance from previous employer.'];
    }
    create_document_requirements($con, $company_id, $app_id, $user_id, $default_req_items, $company_id);

    // Re-fetch seeded requirements
    $req_res = mysqli_query($con, "SELECT * FROM hiring_document_requirements WHERE application_id = $app_id ORDER BY id ASC");
    if ($req_res) {
        while ($r = mysqli_fetch_assoc($req_res)) {
            $reqs[$r['id']] = $r;
            $reqs[$r['id']]['uploaded_doc'] = null;
        }
    }
}

$doc_res = mysqli_query($con, "SELECT * FROM candidate_documents WHERE application_id = $app_id");
$verified_count = 0;
$total_required_count = 0;

if ($doc_res) {
    while ($d = mysqli_fetch_assoc($doc_res)) {
        $r_id = $d['requirement_id'];
        if (isset($reqs[$r_id])) {
            $reqs[$r_id]['uploaded_doc'] = $d;
        }
        if ($d['status'] === 'VERIFIED') {
            $verified_count++;
        }
    }
}

foreach ($reqs as $r) {
    if (!empty($r['is_required']) || !empty($r['required'])) $total_required_count++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Hiring & Onboarding | NovaHire</title>
    <?php include __DIR__ . '/../includes/links.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Manrope', 'Inter', sans-serif; background: #f0f4f8; margin: 0; color: #1e293b; }
        [data-theme="dark"] body { background: #0b1120; color: #f8fafc; }

        .page-container { max-width: 950px; margin: 36px auto; padding: 0 20px; }
        .back-link { display: inline-flex; align-items: center; gap: 8px; color: #64748b; text-decoration: none; margin-bottom: 20px; font-weight: 600; }
        .back-link:hover { color: #3b82f6; }

        .hero-card {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: #fff;
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 24px;
            box-shadow: 0 10px 25px -5px rgba(79, 70, 229, 0.3);
        }
        .hero-card h1 { margin: 0 0 8px 0; font-family: 'Sora', sans-serif; font-size: 24px; font-weight: 800; }
        .hero-card p { margin: 0; opacity: 0.9; font-size: 15px; }

        .joining-badge-box {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(8px);
            padding: 10px 20px;
            border-radius: 30px;
            margin-top: 18px;
            border: 1px solid rgba(255, 255, 255, 0.25);
        }

        .card { background: #fff; border-radius: 14px; padding: 26px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.06); margin-bottom: 24px; border: 1px solid #e2e8f0; }
        [data-theme="dark"] .card { background: #1e293b; border-color: #334155; box-shadow: none; }

        .doc-item {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
        }
        [data-theme="dark"] .doc-item { background: #0f172a; border-color: #334155; }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 18px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-primary { background: #3b82f6; color: #fff; }
        .btn-success { background: #10b981; color: #fff; }
        .btn-outline { background: transparent; border: 1px solid #cbd5e1; color: #475569; }
        [data-theme="dark"] .btn-outline { border-color: #475569; color: #cbd5e1; }
        .btn:hover { opacity: 0.9; }

        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .status-VERIFIED { background: #dcfce7; color: #166534; }
        .status-REJECTED { background: #fee2e2; color: #991b1b; }
        .status-UPLOADED, .status-UNDER_REVIEW { background: #fef3c7; color: #b45309; }
        .status-AWAITING { background: #e2e8f0; color: #475569; }

        .alert { padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; font-size: 14px; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="page-container">
    <a href="my_application.php" class="back-link">
        <i class="fas fa-arrow-left"></i> Back to My Applications
    </a>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <!-- Hero Card -->
    <div class="hero-card">
        <h1>🎉 Congratulations, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Candidate'); ?>!</h1>
        <p>You have been formally selected for the position of <strong><?php echo htmlspecialchars($app['job_title']); ?></strong> at <strong><?php echo htmlspecialchars($company_name); ?></strong>.</p>
        
        <div class="joining-badge-box">
            <i class="fas fa-calendar-check" style="font-size: 1.2rem;"></i>
            <div>
                <span style="font-size: 11px; text-transform: uppercase; font-weight: 700; opacity: 0.85; display: block;">
                    <?php echo !empty($app['final_joining_date']) ? 'Official Joining Date' : 'Your Proposed Availability Date'; ?>
                </span>
                <strong style="font-size: 16px;">
                    <?php echo date('l, F j, Y', strtotime($app['final_joining_date'] ?: ($cea['approximate_joining_date'] ?? date('Y-m-d')))); ?>
                </strong>
            </div>
        </div>

        <div style="margin-top: 14px;">
            <a href="selection_response.php?application_id=<?php echo $app_id; ?>" style="color: rgba(255, 255, 255, 0.9); font-size: 13px; text-decoration: underline;">
                <i class="fas fa-pen"></i> Review or Update Your Selection Response
            </a>
        </div>

        <?php if (!empty($app['hiring_letter_id']) && $app['letter_status'] === 'SENT'): ?>
            <div style="margin-top: 18px;">
                <a href="view_hiring_letter.php?id=<?php echo $app['hiring_letter_id']; ?>" class="btn" style="background: #10b981; color: #fff; font-size: 14px; padding: 10px 22px; border-radius: 30px; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);">
                    <i class="fas fa-file-contract"></i> View Official Appointment Letter &rarr;
                </a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Onboarding Documents Card -->
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
            <div>
                <h2 style="font-family: 'Sora', sans-serif; font-size: 18px; margin: 0;">Required Hiring Documents</h2>
                <p style="color: #64748b; font-size: 13px; margin: 4px 0 0;">
                    Please upload the requested identity and credential documents for onboarding verification.
                </p>
            </div>
            <div style="font-size: 13px; font-weight: 700; color: #10b981; background: rgba(16, 185, 129, 0.1); padding: 6px 14px; border-radius: 20px;">
                <?php echo $verified_count; ?> of <?php echo count($reqs); ?> Documents Verified
            </div>
        </div>

        <?php if (empty($reqs)): ?>
            <div style="text-align: center; padding: 30px; color: #64748b;">
                <i class="fas fa-folder-open" style="font-size: 2rem; opacity: 0.4;"></i>
                <p style="margin-top: 8px;">No specific documents requested at this time.</p>
            </div>
        <?php else: ?>
            <?php foreach ($reqs as $r): 
                $doc = $r['uploaded_doc'];
                $status = $doc ? $doc['status'] : 'AWAITING';
            ?>
                <div class="doc-item">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px;">
                        <div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <h3 style="margin: 0; font-size: 15px; font-weight: 700;"><?php echo htmlspecialchars($r['title']); ?></h3>
                                <span class="status-badge status-<?php echo $status; ?>">
                                    <?php echo ($status === 'AWAITING') ? 'Action Required' : $status; ?>
                                </span>
                                <?php if (!empty($r['is_required']) || !empty($r['required'])): ?>
                                    <span style="font-size: 11px; background: rgba(239, 68, 68, 0.1); color: #ef4444; font-weight: 700; padding: 2px 8px; border-radius: 4px;">
                                        Mandatory
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($r['description'])): ?>
                                <p style="margin: 4px 0 0; font-size: 13px; color: #64748b;"><?php echo htmlspecialchars($r['description']); ?></p>
                            <?php endif; ?>
                        </div>

                        <?php if ($doc): ?>
                            <div>
                                <a href="../api/serve_document.php?id=<?php echo $doc['id']; ?>" target="_blank" class="btn btn-outline">
                                    <i class="fas fa-eye"></i> View Uploaded File
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($doc): ?>
                        <div style="margin-top: 12px; padding-top: 10px; border-top: 1px dashed #cbd5e1; font-size: 12px; color: #64748b;">
                            <i class="fas fa-file"></i> <strong><?php echo htmlspecialchars($doc['original_filename'] ?? $doc['original_name'] ?? 'Uploaded Document'); ?></strong> &middot; 
                            Uploaded on <?php echo date('M d, Y h:i A', strtotime($doc['uploaded_at'])); ?>

                            <?php if ($doc['status'] === 'VERIFIED'): ?>
                                <div style="color: #10b981; font-weight: 700; margin-top: 4px;">
                                    <i class="fas fa-check-circle"></i> Document verified and accepted by company.
                                </div>
                            <?php elseif ($doc['status'] === 'REJECTED'): ?>
                                <div style="color: #ef4444; font-weight: 700; margin-top: 6px; background: #fee2e2; padding: 8px 12px; border-radius: 6px;">
                                    <i class="fas fa-circle-exclamation"></i> Rejection Reason from Company: "<?php echo htmlspecialchars($doc['rejection_reason']); ?>"
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!$doc || $doc['status'] === 'REJECTED'): ?>
                        <div style="margin-top: 14px; padding-top: 12px; border-top: 1px dashed #cbd5e1;">
                            <form method="POST" enctype="multipart/form-data" style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                <input type="hidden" name="action" value="upload_doc">
                                <input type="hidden" name="requirement_id" value="<?php echo $r['id']; ?>">
                                <input type="file" name="doc_file" required accept=".pdf,.jpg,.jpeg,.png,.webp" style="font-size: 13px;">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-upload"></i> <?php echo ($status === 'REJECTED') ? 'Re-upload Document' : 'Upload Document'; ?>
                                </button>
                            </form>
                            <small style="color: #64748b; font-size: 11px; display: block; margin-top: 4px;">
                                Allowed: PDF, JPG, PNG, WEBP (Max 10MB).
                            </small>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
