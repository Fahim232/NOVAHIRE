<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/hiring_workflow.php';
global $con;

// Verify company authentication
if (!isset($_SESSION['company_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$company_id = intval($_SESSION['company_id']);
$company_name = $_SESSION['company_name'] ?? 'Company';

$app_id = intval($_GET['application_id'] ?? 0);
if ($app_id <= 0) {
    header('Location: view_applicants.php');
    exit;
}

// Fetch application and candidate details
$app_stmt = mysqli_prepare($con, "
    SELECT ja.*, cj.job_title, ui.username, ui.email, ui.phone,
           hd.decision, hd.final_joining_date, hd.employment_status_noted
    FROM job_applications ja
    JOIN company_jobs cj ON ja.job_id = cj.id
    JOIN user_info ui ON ja.user_id = ui.id
    LEFT JOIN hiring_decisions hd ON ja.id = hd.application_id
    WHERE ja.id = ? AND ja.company_id = ?
");
mysqli_stmt_bind_param($app_stmt, "ii", $app_id, $company_id);
mysqli_stmt_execute($app_stmt);
$app_res = mysqli_stmt_get_result($app_stmt);
$app = mysqli_fetch_assoc($app_res);
mysqli_stmt_close($app_stmt);

if (!$app) {
    die("Application not found or unauthorized.");
}

$seeker_id = intval($app['user_id']);
$cea = get_candidate_selection_response($con, $app_id);
$success_msg = '';
$error_msg = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'verify_doc') {
        $doc_id = intval($_POST['doc_id'] ?? 0);
        $res = update_document_verification($con, $doc_id, $company_id, 'VERIFIED', null, $company_id);
        if ($res['success']) {
            $success_msg = "Document verified successfully.";
        } else {
            $error_msg = "Verification failed: " . $res['error'];
        }
    } elseif ($action === 'reject_doc') {
        $doc_id = intval($_POST['doc_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? '');
        if (empty($reason)) {
            $error_msg = "A rejection reason is required so the candidate knows how to rectify.";
        } else {
            $res = update_document_verification($con, $doc_id, $company_id, 'REJECTED', $reason, $company_id);
            if ($res['success']) {
                $success_msg = "Document rejected. Candidate has been notified with the reason for resubmission.";
            } else {
                $error_msg = "Rejection failed: " . $res['error'];
            }
        }
    } elseif ($action === 'add_requirement') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $required = isset($_POST['required']) ? 1 : 0;

        if (empty($title)) {
            $error_msg = "Document requirement title cannot be empty.";
        } else {
            $doc_type = 'custom_' . time();
            $ins_stmt = mysqli_prepare($con, "
                INSERT INTO hiring_document_requirements (company_id, application_id, candidate_id, document_type, title, description, is_required, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'REQUESTED', ?)
            ");
            mysqli_stmt_bind_param($ins_stmt, "iiisssii", $company_id, $app_id, $seeker_id, $doc_type, $title, $description, $required, $company_id);
            if (mysqli_stmt_execute($ins_stmt)) {
                $req_id = mysqli_insert_id($con);
                $success_msg = "Requirement '{$title}' added successfully.";
                require_once __DIR__ . '/../includes/functions.php';
                create_notification(
                    $con,
                    'user',
                    $seeker_id,
                    'company',
                    $company_id,
                    "Additional Document Requested",
                    "Company {$company_name} requested document: {$title}",
                    'document_requested',
                    'hiring_document_requirement',
                    $req_id
                );
            } else {
                $error_msg = "Failed to add requirement: " . mysqli_error($con);
            }
            mysqli_stmt_close($ins_stmt);
        }
    }
}

// Fetch all requirements for this application
$reqs = [];
$req_res = mysqli_query($con, "SELECT * FROM hiring_document_requirements WHERE application_id = $app_id ORDER BY id ASC");
if ($req_res) {
    while ($r = mysqli_fetch_assoc($req_res)) {
        $reqs[$r['id']] = $r;
        $reqs[$r['id']]['uploaded_docs'] = [];
    }
}

// Fetch all uploaded candidate documents
$doc_res = mysqli_query($con, "SELECT * FROM candidate_documents WHERE application_id = $app_id ORDER BY id DESC");
$total_uploaded = 0;
$total_verified = 0;
$total_pending = 0;
$total_rejected = 0;

if ($doc_res) {
    while ($d = mysqli_fetch_assoc($doc_res)) {
        $total_uploaded++;
        if ($d['status'] === 'VERIFIED') $total_verified++;
        elseif ($d['status'] === 'REJECTED') $total_rejected++;
        else $total_pending++;

        $r_id = $d['requirement_id'];
        if (isset($reqs[$r_id])) {
            $reqs[$r_id]['uploaded_docs'][] = $d;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Candidate Onboarding Documents | <?php echo htmlspecialchars($company_name); ?></title>
    <?php include '../includes/links.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Manrope', 'Inter', sans-serif; background: #f0f4f8; margin: 0; color: #1e293b; }
        [data-theme="dark"] body { background: #0b1120; color: #f8fafc; }

        .page-container { max-width: 1050px; margin: 36px auto; padding: 0 20px; }
        .back-link { display: inline-flex; align-items: center; gap: 8px; color: #64748b; text-decoration: none; margin-bottom: 20px; font-weight: 600; }
        .back-link:hover { color: #3b82f6; }

        .card { background: #fff; border-radius: 14px; padding: 26px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.06); margin-bottom: 24px; border: 1px solid #e2e8f0; }
        [data-theme="dark"] .card { background: #1e293b; border-color: #334155; box-shadow: none; }

        .header-box { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
        .candidate-title h1 { margin: 0 0 6px 0; font-family: 'Sora', sans-serif; font-size: 22px; font-weight: 800; }
        .candidate-title p { margin: 0; color: #64748b; font-size: 14px; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 24px; }
        .stat-card { background: #fff; border-radius: 12px; padding: 18px; border: 1px solid #e2e8f0; text-align: center; }
        [data-theme="dark"] .stat-card { background: #1e293b; border-color: #334155; }
        .stat-number { font-size: 28px; font-weight: 800; font-family: 'Sora', sans-serif; }
        .stat-label { font-size: 12px; text-transform: uppercase; font-weight: 700; color: #64748b; margin-top: 4px; }

        .req-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 16px; transition: border-color 0.2s; }
        [data-theme="dark"] .req-card { background: #0f172a; border-color: #334155; }
        .req-card:hover { border-color: #cbd5e1; }

        .badge-status { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; }
        .badge-VERIFIED { background: #dcfce7; color: #166534; }
        .badge-REJECTED { background: #fee2e2; color: #991b1b; }
        .badge-UPLOADED, .badge-UNDER_REVIEW { background: #fef3c7; color: #b45309; }
        .badge-AWAITING { background: #e2e8f0; color: #475569; }

        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px; font-weight: 600; font-size: 13px; text-decoration: none; border: none; cursor: pointer; transition: all 0.2s; }
        .btn-primary { background: #3b82f6; color: #fff; }
        .btn-success { background: #10b981; color: #fff; }
        .btn-danger { background: #ef4444; color: #fff; }
        .btn-outline { background: transparent; border: 1px solid #cbd5e1; color: #475569; }
        [data-theme="dark"] .btn-outline { border-color: #475569; color: #cbd5e1; }
        .btn:hover { opacity: 0.9; }

        .alert { padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; font-size: 14px; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        .modal-bg { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal-box { background: #fff; border-radius: 12px; padding: 24px; max-width: 480px; width: 90%; }
        [data-theme="dark"] .modal-box { background: #1e293b; color: #f8fafc; }
    </style>
</head>
<body>
<?php include 'company_header.php'; ?>

<div class="page-container">
    <a href="view_applicant_detail.php?id=<?php echo $app_id; ?>" class="back-link">
        <i class="fas fa-arrow-left"></i> Back to Candidate Profile
    </a>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <?php if ($cea): ?>
        <div class="card" style="border-left: 4px solid #6366f1; background: rgba(99, 102, 241, 0.04); margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px;">
                <div>
                    <h3 style="margin: 0 0 6px; font-size: 15px; color: #4f46e5; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-clipboard-check"></i> Candidate Selection Response
                    </h3>
                    <p style="margin: 0; font-size: 13px; color: #64748b;">
                        Candidate: <strong><?php echo htmlspecialchars($app['username']); ?></strong> &middot; Submitted: <?php echo date('M d, Y, h:i A', strtotime($cea['submitted_at'])); ?>
                    </p>
                </div>
                <a href="candidate_response_review.php?application_id=<?php echo $app_id; ?>" class="btn btn-outline" style="font-size: 12px; padding: 6px 14px;">
                    <i class="fas fa-eye"></i> View Full Response Details
                </a>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-top: 14px; font-size: 13px;">
                <div><strong>Readiness:</strong> <?php echo ($cea['ready_to_join'] === 'yes') ? '<span style="color: #10b981; font-weight: 700;">Ready to Join</span>' : '<span style="color: #ef4444; font-weight: 700;">Declined</span>'; ?></div>
                <?php if ($cea['ready_to_join'] === 'yes'): ?>
                    <div><strong>Approximate Joining Date:</strong> <span style="color: #6366f1; font-weight: 700;"><?php echo date('M d, Y', strtotime($cea['approximate_joining_date'])); ?></span></div>
                    <div><strong>Employment:</strong> <?php echo ($cea['currently_working'] === 'yes') ? 'Working at ' . htmlspecialchars($cea['current_company_name'] ?: 'another firm') : 'Immediate Availability'; ?></div>
                    <?php if ($cea['currently_working'] === 'yes' && !empty($cea['expected_leaving_date'])): ?>
                        <div><strong>Leaving Date:</strong> <?php echo date('M d, Y', strtotime($cea['expected_leaving_date'])); ?></div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="header-box">
            <div class="candidate-title">
                <h1>Candidate Onboarding Documents</h1>
                <p>
                    Candidate: <strong><?php echo htmlspecialchars($app['username']); ?></strong> &middot; 
                    Position: <strong><?php echo htmlspecialchars($app['job_title']); ?></strong>
                    <?php if (!empty($app['final_joining_date'])): ?>
                        &middot; Confirmed Joining: <strong style="color: #10b981;"><?php echo date('M d, Y', strtotime($app['final_joining_date'])); ?></strong>
                    <?php endif; ?>
                </p>
            </div>
            <div>
                <a href="hiring_letter.php?application_id=<?php echo $app_id; ?>" class="btn btn-primary" style="background: linear-gradient(135deg, #10b981, #059669);">
                    <i class="fas fa-file-contract"></i> Appointment Letter Studio &rarr;
                </a>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number" style="color: #6366f1;"><?php echo count($reqs); ?></div>
                <div class="stat-label">Total Requirements</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #3b82f6;"><?php echo $total_uploaded; ?></div>
                <div class="stat-label">Documents Uploaded</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #10b981;"><?php echo $total_verified; ?></div>
                <div class="stat-label">Verified & Approved</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #d97706;"><?php echo $total_pending; ?></div>
                <div class="stat-label">Pending Verification</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #ef4444;"><?php echo $total_rejected; ?></div>
                <div class="stat-label">Rejected / Resubmit</div>
            </div>
        </div>
    </div>

    <!-- Requirements List -->
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="font-family: 'Sora', sans-serif; font-size: 18px; margin: 0;">Required Document Checklist</h2>
            <button onclick="document.getElementById('addReqModal').style.display='flex';" class="btn btn-outline">
                <i class="fas fa-plus"></i> Request Additional Document
            </button>
        </div>

        <?php if (empty($reqs)): ?>
            <div style="text-align: center; padding: 40px 20px; color: #64748b;">
                <i class="fas fa-folder-open" style="font-size: 2.5rem; opacity: 0.4;"></i>
                <p style="margin-top: 10px;">No document requirements have been created yet.</p>
            </div>
        <?php else: ?>
            <?php foreach ($reqs as $r): 
                $has_upload = !empty($r['uploaded_docs']);
                $latest_doc = $has_upload ? $r['uploaded_docs'][0] : null;
                $status = $latest_doc ? $latest_doc['status'] : 'AWAITING';
            ?>
                <div class="req-card">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px;">
                        <div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <h3 style="margin: 0; font-size: 16px; font-weight: 700;"><?php echo htmlspecialchars($r['title']); ?></h3>
                                <span class="badge-status badge-<?php echo $status; ?>">
                                    <?php echo ($status === 'AWAITING') ? 'Awaiting Upload' : $status; ?>
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

                        <?php if ($latest_doc): ?>
                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                <a href="../api/serve_document.php?id=<?php echo $latest_doc['id']; ?>" target="_blank" class="btn btn-outline">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <a href="../api/serve_document.php?id=<?php echo $latest_doc['id']; ?>&download=1" class="btn btn-outline">
                                    <i class="fas fa-download"></i> Download
                                </a>
                                <?php if ($latest_doc['status'] !== 'VERIFIED'): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="verify_doc">
                                        <input type="hidden" name="doc_id" value="<?php echo $latest_doc['id']; ?>">
                                        <button type="submit" class="btn btn-success" onclick="return confirm('Verify and approve this document?');">
                                            <i class="fas fa-check"></i> Verify
                                        </button>
                                    </form>
                                    <button class="btn btn-danger" onclick="openRejectModal(<?php echo $latest_doc['id']; ?>, '<?php echo htmlspecialchars(addslashes($r['title'])); ?>');">
                                        <i class="fas fa-xmark"></i> Reject
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($latest_doc): ?>
                        <div style="margin-top: 14px; padding-top: 12px; border-top: 1px dashed #cbd5e1; font-size: 12px; color: #64748b; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                            <div>
                                <i class="fas fa-file"></i> <strong><?php echo htmlspecialchars($latest_doc['original_name']); ?></strong> &middot; 
                                <?php echo round($latest_doc['file_size'] / 1024, 1); ?> KB &middot; 
                                Uploaded on <?php echo date('M d, Y h:i A', strtotime($latest_doc['uploaded_at'])); ?>
                            </div>
                            <?php if ($latest_doc['status'] === 'VERIFIED' && !empty($latest_doc['verified_at'])): ?>
                                <div style="color: #10b981; font-weight: 600;">
                                    <i class="fas fa-check-double"></i> Verified on <?php echo date('M d, Y', strtotime($latest_doc['verified_at'])); ?>
                                </div>
                            <?php elseif ($latest_doc['status'] === 'REJECTED' && !empty($latest_doc['rejection_reason'])): ?>
                                <div style="color: #ef4444; font-weight: 600; width: 100%; margin-top: 4px;">
                                    <i class="fas fa-circle-exclamation"></i> Rejection Reason: "<?php echo htmlspecialchars($latest_doc['rejection_reason']); ?>"
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Add Requirement Modal -->
<div class="modal-bg" id="addReqModal">
    <div class="modal-box">
        <h3 style="margin-top: 0; font-family: 'Sora', sans-serif;">Request Additional Document</h3>
        <form method="POST">
            <input type="hidden" name="action" value="add_requirement">
            <div style="margin-bottom: 14px;">
                <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px;">Document Title *</label>
                <input type="text" name="title" required placeholder="e.g. Police Clearance, Portfolio PDF" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1; box-sizing: border-box;">
            </div>
            <div style="margin-bottom: 14px;">
                <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px;">Instructions / Description</label>
                <textarea name="description" rows="2" placeholder="Specific requirements or guidance..." style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1; box-sizing: border-box;"></textarea>
            </div>
            <div style="margin-bottom: 20px;">
                <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; cursor: pointer;">
                    <input type="checkbox" name="required" value="1" checked style="width: 16px; height: 16px;">
                    Mandatory for Onboarding
                </label>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('addReqModal').style.display='none';">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Requirement</button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal-bg" id="rejectModal">
    <div class="modal-box">
        <h3 style="margin-top: 0; font-family: 'Sora', sans-serif; color: #ef4444;"><i class="fas fa-triangle-exclamation"></i> Reject Document</h3>
        <p style="font-size: 13px; color: #64748b; margin-top: -6px;">Specify the reason why this document cannot be accepted. The candidate will be notified to re-upload.</p>
        <form method="POST">
            <input type="hidden" name="action" value="reject_doc">
            <input type="hidden" name="doc_id" id="reject_doc_id" value="">
            <div style="margin-bottom: 18px;">
                <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px;">Rejection Reason *</label>
                <textarea name="rejection_reason" id="reject_reason_text" rows="3" required placeholder="e.g. Scanned copy is blurry; please upload a clear, full-page PDF." style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1; box-sizing: border-box;"></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('rejectModal').style.display='none';">Cancel</button>
                <button type="submit" class="btn btn-danger">Confirm Rejection</button>
            </div>
        </form>
    </div>
</div>

<script>
function openRejectModal(docId, docTitle) {
    document.getElementById('reject_doc_id').value = docId;
    document.getElementById('reject_reason_text').value = '';
    document.getElementById('rejectModal').style.display = 'flex';
}
</script>
</body>
</html>
