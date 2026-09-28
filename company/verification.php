<?php
/**
 * NovaHire — Company Verification Portal
 * 
 * Allows employers to review their business verification status,
 * submit legal documentation (Trade License, Certificate of Incorporation, etc.),
 * view admin feedback, and manage verification evidence.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/company_verification.php';
global $con;

// Verify company is logged in
require_company_login();

$company_id   = intval($_SESSION['company_id']);
$company_name = $_SESSION['company_name'] ?? 'Company';

$success_msg = '';
$error_msg   = '';

// Check if redirected with notice
if (isset($_GET['notice']) && $_GET['notice'] === 'verification_required') {
    $error_msg = '🔒 Company Verification Required: You must submit valid business documentation and receive administrator verification before posting jobs or accessing recruitment tools.';
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = 'Your session expired. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'upload_document') {
            $meta = [
                'document_type'     => trim($_POST['document_type'] ?? 'trade_license'),
                'document_title'    => trim($_POST['document_title'] ?? ''),
                'document_number'   => trim($_POST['document_number'] ?? ''),
                'issuing_authority' => trim($_POST['issuing_authority'] ?? ''),
                'issue_date'        => trim($_POST['issue_date'] ?? ''),
                'expiry_date'       => trim($_POST['expiry_date'] ?? ''),
            ];

            $res = nh_submit_verification_document($con, $company_id, $_FILES['verification_file'] ?? null, $meta);
            if ($res['ok']) {
                $success_msg = 'Document uploaded successfully! Your verification request has been queued for administrator review.';
            } else {
                $error_msg = $res['error'] ?? 'Failed to upload document.';
            }

        } elseif ($action === 'delete_document') {
            $doc_id = intval($_POST['doc_id'] ?? 0);
            $res = nh_delete_verification_document($con, $company_id, $doc_id);
            if ($res['ok']) {
                $success_msg = 'Document removed successfully.';
            } else {
                $error_msg = $res['error'] ?? 'Failed to delete document.';
            }
        }
    }
}

// Fetch current company verification status and documents
$company_verif = nh_get_company_verification($con, $company_id);
$status_key    = $company_verif['verification_status'] ?? VERIF_STATUS_PENDING;
$status_info   = nh_verification_status_info($status_key);
$documents     = nh_get_company_documents($con, $company_id);
$history       = nh_get_verification_history($con, $company_id);
$doc_types     = nh_verification_document_types();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Verification | NovaHire Recruiter Portal</title>
    <?php include '../includes/links.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --cv-bg: #f8fafc;
            --cv-card: #ffffff;
            --cv-border: #e2e8f0;
            --cv-text: #0f172a;
            --cv-muted: #64748b;
            --cv-primary: #1a56db;
            --cv-primary-light: rgba(26, 86, 219, 0.08);
            --cv-radius: 18px;
            --cv-shadow: 0 10px 30px -5px rgba(15, 23, 42, 0.06), 0 4px 6px -2px rgba(15, 23, 42, 0.03);
        }
        [data-theme="dark"] {
            --cv-bg: #0b0f19;
            --cv-card: #131b2e;
            --cv-border: #1e293b;
            --cv-text: #f1f5f9;
            --cv-muted: #94a3b8;
            --cv-primary: #38bdf8;
            --cv-primary-light: rgba(56, 189, 248, 0.12);
            --cv-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.4);
        }

        body {
            background-color: var(--cv-bg);
            color: var(--cv-text);
            font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
            min-height: 100vh;
        }

        .cv-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 36px 24px 80px;
        }

        /* ── Header Breadcrumbs ── */
        .cv-page-head {
            margin-bottom: 28px;
        }
        .cv-crumbs {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            color: var(--cv-muted);
            margin-bottom: 8px;
        }
        .cv-crumbs a { color: var(--cv-muted); text-decoration: none; transition: color .2s; }
        .cv-crumbs a:hover { color: var(--cv-primary); }
        .cv-page-title {
            font-size: 1.85rem;
            font-weight: 800;
            color: var(--cv-text);
            letter-spacing: -0.5px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        /* ── Status Banner Card ── */
        .cv-status-card {
            background: var(--cv-card);
            border: 1.5px solid var(--cv-border);
            border-radius: var(--cv-radius);
            padding: 28px 32px;
            margin-bottom: 32px;
            box-shadow: var(--cv-shadow);
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: flex-start;
            gap: 24px;
        }
        .cv-status-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 6px;
            background: <?php echo $status_info['color']; ?>;
        }
        .cv-status-icon-wrap {
            width: 64px;
            height: 64px;
            border-radius: 18px;
            background: <?php echo $status_info['bg']; ?>;
            color: <?php echo $status_info['color']; ?>;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.85rem;
            flex-shrink: 0;
        }
        .cv-status-content {
            flex: 1;
        }
        .cv-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: <?php echo $status_info['bg']; ?>;
            color: <?php echo $status_info['color']; ?>;
            margin-bottom: 10px;
        }
        .cv-status-title {
            font-size: 1.35rem;
            font-weight: 800;
            margin: 0 0 8px;
            color: var(--cv-text);
        }
        .cv-status-desc {
            font-size: 0.95rem;
            line-height: 1.6;
            color: var(--cv-muted);
            margin: 0;
            max-width: 800px;
        }

        /* Rejection / Resubmission Alert Box */
        .cv-feedback-box {
            margin-top: 18px;
            background: rgba(220, 38, 38, 0.06);
            border: 1px solid rgba(220, 38, 38, 0.2);
            border-radius: 12px;
            padding: 16px 20px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }
        .cv-feedback-box.resubmission {
            background: rgba(2, 132, 199, 0.06);
            border-color: rgba(2, 132, 199, 0.2);
        }
        .cv-feedback-box i {
            font-size: 1.2rem;
            margin-top: 2px;
        }
        .cv-feedback-box.resubmission i { color: #0284c7; }
        .cv-feedback-box:not(.resubmission) i { color: #dc2626; }
        .cv-feedback-title {
            font-size: 0.88rem;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .cv-feedback-text {
            font-size: 0.9rem;
            color: var(--cv-text);
            margin: 0;
            line-height: 1.5;
        }

        /* ── Grid Layout ── */
        .cv-grid {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 28px;
            align-items: start;
        }
        @media (max-width: 992px) {
            .cv-grid { grid-template-columns: 1fr; }
        }

        /* ── Cards ── */
        .cv-card {
            background: var(--cv-card);
            border: 1.5px solid var(--cv-border);
            border-radius: var(--cv-radius);
            box-shadow: var(--cv-shadow);
            padding: 28px;
            margin-bottom: 28px;
        }
        .cv-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--cv-border);
        }
        .cv-card-title {
            font-size: 1.15rem;
            font-weight: 800;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--cv-text);
        }
        .cv-card-title i {
            color: var(--cv-primary);
        }

        /* ── Forms ── */
        .cv-form-group {
            margin-bottom: 20px;
        }
        .cv-form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--cv-text);
            margin-bottom: 8px;
        }
        .cv-form-group label .req { color: #ef4444; }
        .cv-input, .cv-select {
            width: 100%;
            padding: 12px 16px;
            border-radius: 12px;
            border: 1.5px solid var(--cv-border);
            background: var(--cv-bg);
            color: var(--cv-text);
            font-family: inherit;
            font-size: 0.92rem;
            outline: none;
            transition: all 0.2s;
            box-sizing: border-box;
        }
        .cv-input:focus, .cv-select:focus {
            border-color: var(--cv-primary);
            box-shadow: 0 0 0 3px var(--cv-primary-light);
        }
        .cv-help {
            font-size: 0.78rem;
            color: var(--cv-muted);
            margin-top: 6px;
            display: block;
        }
        .cv-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        @media (max-width: 576px) {
            .cv-row { grid-template-columns: 1fr; }
        }

        /* ── Drag & Drop Upload Zone ── */
        .cv-dropzone {
            border: 2px dashed var(--cv-border);
            border-radius: 14px;
            padding: 28px 20px;
            text-align: center;
            background: var(--cv-bg);
            cursor: pointer;
            transition: all 0.25s ease;
            position: relative;
        }
        .cv-dropzone:hover, .cv-dropzone.dragover {
            border-color: var(--cv-primary);
            background: var(--cv-primary-light);
        }
        .cv-dropzone-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: var(--cv-card);
            color: var(--cv-primary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            margin-bottom: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .cv-dropzone-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--cv-text);
            margin-bottom: 4px;
        }
        .cv-dropzone-desc {
            font-size: 0.8rem;
            color: var(--cv-muted);
        }
        .cv-dropzone input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }
        .cv-file-chosen {
            display: none;
            margin-top: 14px;
            padding: 10px 16px;
            background: var(--cv-card);
            border: 1px solid var(--cv-border);
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--cv-text);
            align-items: center;
            gap: 10px;
            text-align: left;
        }

        .cv-btn-submit {
            width: 100%;
            padding: 14px;
            border-radius: 12px;
            background: linear-gradient(135deg, #1a56db, #0ea5e9);
            color: #fff;
            font-weight: 700;
            font-size: 0.95rem;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.25s;
            box-shadow: 0 4px 14px rgba(26, 86, 219, 0.3);
        }
        .cv-btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(26, 86, 219, 0.4);
        }

        /* ── Document Item Cards ── */
        .cv-doc-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .cv-doc-item {
            background: var(--cv-bg);
            border: 1px solid var(--cv-border);
            border-radius: 14px;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            transition: all 0.2s;
        }
        .cv-doc-item:hover {
            border-color: var(--cv-primary);
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
        }
        .cv-doc-info {
            display: flex;
            align-items: center;
            gap: 16px;
            min-width: 0;
            flex: 1;
        }
        .cv-doc-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: var(--cv-primary-light);
            color: var(--cv-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }
        .cv-doc-meta {
            min-width: 0;
            flex: 1;
        }
        .cv-doc-name {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--cv-text);
            margin: 0 0 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .cv-doc-sub {
            font-size: 0.78rem;
            color: var(--cv-muted);
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        .cv-doc-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }
        .cv-doc-btn {
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 0.82rem;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
            border: 1px solid var(--cv-border);
            background: var(--cv-card);
            color: var(--cv-text);
            cursor: pointer;
        }
        .cv-doc-btn:hover {
            border-color: var(--cv-primary);
            color: var(--cv-primary);
            background: var(--cv-primary-light);
        }
        .cv-doc-btn.delete {
            color: #ef4444;
            border-color: rgba(239, 68, 68, 0.2);
        }
        .cv-doc-btn.delete:hover {
            background: rgba(239, 68, 68, 0.1);
        }

        /* Empty State */
        .cv-empty {
            text-align: center;
            padding: 40px 20px;
            color: var(--cv-muted);
        }
        .cv-empty-icon {
            font-size: 2.5rem;
            color: var(--cv-border);
            margin-bottom: 12px;
        }

        /* ── Document Type Guide Pills ── */
        .cv-guide-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
        }
        .cv-guide-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 9999px;
            background: var(--cv-bg);
            border: 1px solid var(--cv-border);
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--cv-text);
        }
        .cv-guide-pill i { color: #059669; }

        /* ── Audit Timeline ── */
        .cv-timeline {
            position: relative;
            padding-left: 24px;
        }
        .cv-timeline::before {
            content: '';
            position: absolute;
            left: 7px;
            top: 6px;
            bottom: 6px;
            width: 2px;
            background: var(--cv-border);
        }
        .cv-tl-item {
            position: relative;
            margin-bottom: 20px;
        }
        .cv-tl-item:last-child { margin-bottom: 0; }
        .cv-tl-dot {
            position: absolute;
            left: -24px;
            top: 4px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: var(--cv-primary);
            border: 3px solid var(--cv-card);
            box-shadow: 0 0 0 2px var(--cv-border);
        }
        .cv-tl-title {
            font-size: 0.86rem;
            font-weight: 700;
            color: var(--cv-text);
            margin: 0 0 2px;
        }
        .cv-tl-sub {
            font-size: 0.76rem;
            color: var(--cv-muted);
            margin: 0;
        }
        .cv-tl-reason {
            font-size: 0.8rem;
            color: var(--cv-text);
            margin: 4px 0 0;
            background: var(--cv-bg);
            padding: 6px 10px;
            border-radius: 8px;
            border-left: 3px solid var(--cv-primary);
        }
    </style>
</head>
<body>
    <?php include 'company_header.php'; ?>

    <div class="cv-container">
        <!-- Page Header -->
        <div class="cv-page-head">
            <div class="cv-crumbs">
                <a href="index.php"><i class="fas fa-grip mr-1"></i> Dashboard</a>
                <i class="fas fa-chevron-right" style="font-size: 0.65rem;"></i>
                <span>Company Verification</span>
            </div>
            <h1 class="cv-page-title">
                <i class="fas fa-shield-halved" style="color: var(--cv-primary);"></i>
                Company Verification & Evidence
            </h1>
        </div>

        <!-- Flash Alerts -->
        <?php if ($success_msg): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius: 12px; margin-bottom: 24px;">
                <i class="fas fa-check-circle mr-2"></i> <?php echo htmlspecialchars($success_msg); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius: 12px; margin-bottom: 24px;">
                <i class="fas fa-triangle-exclamation mr-2"></i> <?php echo htmlspecialchars($error_msg); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
        <?php endif; ?>

        <!-- Status Overview Card -->
        <div class="cv-status-card">
            <div class="cv-status-icon-wrap">
                <i class="fas <?php echo $status_info['icon']; ?>"></i>
            </div>
            <div class="cv-status-content">
                <span class="cv-status-pill">
                    <i class="fas <?php echo $status_info['icon']; ?>"></i>
                    <?php echo htmlspecialchars($status_info['label']); ?>
                </span>
                <h2 class="cv-status-title"><?php echo htmlspecialchars($status_info['title']); ?></h2>
                <p class="cv-status-desc"><?php echo htmlspecialchars($status_info['desc']); ?></p>

                <?php if ($status_key === VERIF_STATUS_VERIFIED && !empty($company_verif['verified_at'])): ?>
                    <p style="margin-top: 10px; font-size: 0.85rem; color: #059669; font-weight: 600;">
                        <i class="fas fa-calendar-check mr-1"></i> Verified on <?php echo date('M j, Y g:i A', strtotime($company_verif['verified_at'])); ?>
                    </p>
                <?php endif; ?>

                <?php if ($status_key === VERIF_STATUS_REJECTED && !empty($company_verif['verification_rejection_reason'])): ?>
                    <div class="cv-feedback-box">
                        <i class="fas fa-circle-xmark"></i>
                        <div>
                            <div class="cv-feedback-title">Administrator Rejection Reason:</div>
                            <div class="cv-feedback-text"><?php echo nl2br(htmlspecialchars($company_verif['verification_rejection_reason'])); ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($status_key === VERIF_STATUS_RESUBMISSION && !empty($company_verif['verification_rejection_reason'])): ?>
                    <div class="cv-feedback-box resubmission">
                        <i class="fas fa-circle-exclamation"></i>
                        <div>
                            <div class="cv-feedback-title">Administrator Action Request:</div>
                            <div class="cv-feedback-text"><?php echo nl2br(htmlspecialchars($company_verif['verification_rejection_reason'])); ?></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="cv-grid">
            <!-- Left Column: Submit Evidence Form -->
            <div>
                <div class="cv-card">
                    <div class="cv-card-header">
                        <h3 class="cv-card-title">
                            <i class="fas fa-file-arrow-up"></i>
                            Submit Business Evidence
                        </h3>
                    </div>

                    <div style="margin-bottom: 18px;">
                        <div style="font-size: 0.85rem; font-weight: 700; color: var(--cv-muted); margin-bottom: 8px;">Accepted Document Types:</div>
                        <div class="cv-guide-pills">
                            <span class="cv-guide-pill"><i class="fas fa-check-circle"></i> Trade License</span>
                            <span class="cv-guide-pill"><i class="fas fa-check-circle"></i> Inc. Certificate</span>
                            <span class="cv-guide-pill"><i class="fas fa-check-circle"></i> Business Registration</span>
                            <span class="cv-guide-pill"><i class="fas fa-check-circle"></i> TIN / VAT Cert</span>
                        </div>
                    </div>

                    <form method="POST" action="verification.php" enctype="multipart/form-data" id="cvUploadForm">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="upload_document">

                        <div class="cv-form-group">
                            <label for="docType">Document Category <span class="req">*</span></label>
                            <select name="document_type" id="docType" class="cv-select" required>
                                <?php foreach ($doc_types as $t_key => $t_val): ?>
                                    <option value="<?php echo $t_key; ?>">
                                        <?php echo htmlspecialchars($t_val['label']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="cv-help">Select the category that best matches your official government document.</span>
                        </div>

                        <div class="cv-form-group">
                            <label for="docTitle">Document Title <span class="req">*</span></label>
                            <input type="text" name="document_title" id="docTitle" class="cv-input" 
                                   placeholder="e.g. Dhaka North Trade License 2026-2027" required>
                        </div>

                        <div class="cv-row">
                            <div class="cv-form-group">
                                <label for="docNumber">License / Reg. Number</label>
                                <input type="text" name="document_number" id="docNumber" class="cv-input" 
                                       placeholder="e.g. TRAD/DNCC/102938">
                            </div>
                            <div class="cv-form-group">
                                <label for="issuingAuth">Issuing Authority</label>
                                <input type="text" name="issuing_authority" id="issuingAuth" class="cv-input" 
                                       placeholder="e.g. City Corporation / RJSC">
                            </div>
                        </div>

                        <div class="cv-row">
                            <div class="cv-form-group">
                                <label for="issueDate">Issue Date</label>
                                <input type="date" name="issue_date" id="issueDate" class="cv-input">
                            </div>
                            <div class="cv-form-group">
                                <label for="expiryDate">Expiry Date (if applicable)</label>
                                <input type="date" name="expiry_date" id="expiryDate" class="cv-input">
                            </div>
                        </div>

                        <div class="cv-form-group">
                            <label>Document File <span class="req">*</span> <small class="text-muted">(PDF, JPG, PNG, WEBP — Max 10MB)</small></label>
                            <div class="cv-dropzone" id="cvDropzone">
                                <div class="cv-dropzone-icon">
                                    <i class="fas fa-cloud-arrow-up"></i>
                                </div>
                                <div class="cv-dropzone-title">Click or drag &amp; drop document scan</div>
                                <div class="cv-dropzone-desc">Must be high-resolution, clear, and fully legible</div>
                                <input type="file" name="verification_file" id="cvFileInput" accept=".pdf,.jpg,.jpeg,.png,.webp" required onchange="handleFileSelected(this)">
                            </div>
                            <div class="cv-file-chosen" id="cvFileChosen">
                                <i class="fas fa-file-circle-check text-primary"></i>
                                <span id="cvFileName" style="flex:1;"></span>
                                <span id="cvFileSize" class="text-muted font-weight-normal"></span>
                            </div>
                        </div>

                        <button type="submit" class="cv-btn-submit" id="cvSubmitBtn">
                            <i class="fas fa-paper-plane mr-2"></i> Submit Document for Verification
                        </button>
                    </form>
                </div>
            </div>

            <!-- Right Column: Submitted Documents & Audit Trail -->
            <div>
                <!-- Documents Card -->
                <div class="cv-card">
                    <div class="cv-card-header">
                        <h3 class="cv-card-title">
                            <i class="fas fa-folder-open"></i>
                            Submitted Documents (<?php echo count($documents); ?>)
                        </h3>
                    </div>

                    <?php if (empty($documents)): ?>
                        <div class="cv-empty">
                            <div class="cv-empty-icon"><i class="fas fa-folder-open"></i></div>
                            <h6>No Evidence Submitted Yet</h6>
                            <p class="small text-muted mb-0">Upload your Trade License or Business Registration to begin verification.</p>
                        </div>
                    <?php else: ?>
                        <div class="cv-doc-list">
                            <?php foreach ($documents as $doc): 
                                $dtype_info = $doc_types[$doc['document_type']] ?? ['label' => $doc['document_type'], 'icon' => 'fa-file'];
                                $doc_status = $doc['verification_status'] ?? 'pending';
                                $status_badge = 'badge-secondary';
                                if ($doc_status === 'approved') $status_badge = 'badge-success';
                                elseif ($doc_status === 'rejected') $status_badge = 'badge-danger';
                                elseif ($doc_status === 'under_review') $status_badge = 'badge-warning';
                                
                                $size_kb = round($doc['file_size'] / 1024, 1);
                                $size_str = $size_kb > 1024 ? round($size_kb / 1024, 2) . ' MB' : $size_kb . ' KB';
                            ?>
                                <div class="cv-doc-item">
                                    <div class="cv-doc-info">
                                        <div class="cv-doc-icon">
                                            <i class="fas <?php echo $dtype_info['icon'] ?? 'fa-file-lines'; ?>"></i>
                                        </div>
                                        <div class="cv-doc-meta">
                                            <div class="cv-doc-name"><?php echo htmlspecialchars($doc['document_title']); ?></div>
                                            <div class="cv-doc-sub">
                                                <span><span class="badge <?php echo $status_badge; ?>" style="font-size: 0.72rem;"><?php echo ucfirst(str_replace('_', ' ', $doc_status)); ?></span></span>
                                                <span><?php echo htmlspecialchars($dtype_info['label']); ?></span>
                                                <?php if (!empty($doc['document_number'])): ?>
                                                    <span>#<?php echo htmlspecialchars($doc['document_number']); ?></span>
                                                <?php endif; ?>
                                                <span><?php echo $size_str; ?></span>
                                                <span><?php echo date('M j, Y', strtotime($doc['created_at'])); ?></span>
                                            </div>
                                            <?php if (!empty($doc['admin_note'])): ?>
                                                <div style="margin-top: 6px; font-size: 0.78rem; color: #dc2626; background: rgba(220,38,38,0.06); padding: 4px 8px; border-radius: 6px;">
                                                    <i class="fas fa-comment-dots mr-1"></i> Admin Note: <?php echo htmlspecialchars($doc['admin_note']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="cv-doc-actions">
                                        <a href="../api/serve_verification_document.php?id=<?php echo $doc['id']; ?>" target="_blank" class="cv-doc-btn" title="View Document in new tab">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <a href="../api/serve_verification_document.php?id=<?php echo $doc['id']; ?>&download=1" class="cv-doc-btn" title="Download Document">
                                            <i class="fas fa-download"></i>
                                        </a>
                                        <?php if ($status_key !== VERIF_STATUS_VERIFIED): ?>
                                            <form method="POST" action="verification.php" onsubmit="return confirm('Are you sure you want to remove this document?');" style="display:inline;">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete_document">
                                                <input type="hidden" name="doc_id" value="<?php echo $doc['id']; ?>">
                                                <button type="submit" class="cv-doc-btn delete" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Audit History Card -->
                <div class="cv-card">
                    <div class="cv-card-header">
                        <h3 class="cv-card-title">
                            <i class="fas fa-clock-rotate-left"></i>
                            Verification Activity History
                        </h3>
                    </div>

                    <?php if (empty($history)): ?>
                        <div class="cv-empty" style="padding: 20px;">
                            <p class="small text-muted mb-0">No past activity recorded.</p>
                        </div>
                    <?php else: ?>
                        <div class="cv-timeline">
                            <?php foreach ($history as $act): 
                                $act_title = ucwords(str_replace('_', ' ', $act['action']));
                                if ($act['action'] === 'document_uploaded') $act_title = 'Evidence Document Uploaded';
                                elseif ($act['action'] === 'approved') $act_title = 'Verification Approved ✅';
                                elseif ($act['action'] === 'rejected') $act_title = 'Verification Rejected ❌';
                                elseif ($act['action'] === 'resubmission_requested') $act_title = 'Resubmission Requested ⚠️';
                                elseif ($act['action'] === 'submitted') $act_title = 'Company Registered (Pending)';
                            ?>
                                <div class="cv-tl-item">
                                    <div class="cv-tl-dot"></div>
                                    <div class="cv-tl-title"><?php echo htmlspecialchars($act_title); ?></div>
                                    <div class="cv-tl-sub">
                                        <?php echo date('M j, Y — g:i A', strtotime($act['created_at'])); ?>
                                        <?php if (!empty($act['admin_user_name'])): ?>
                                            • by Admin (<?php echo htmlspecialchars($act['admin_user_name']); ?>)
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($act['reason'])): ?>
                                        <div class="cv-tl-reason"><?php echo htmlspecialchars($act['reason']); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        function handleFileSelected(input) {
            const chosen = document.getElementById('cvFileChosen');
            const nameSpan = document.getElementById('cvFileName');
            const sizeSpan = document.getElementById('cvFileSize');
            if (input.files && input.files[0]) {
                const file = input.files[0];
                nameSpan.textContent = file.name;
                const sizeKb = (file.size / 1024).toFixed(1);
                sizeSpan.textContent = sizeKb > 1024 ? (sizeKb / 1024).toFixed(2) + ' MB' : sizeKb + ' KB';
                chosen.style.display = 'flex';
            } else {
                chosen.style.display = 'none';
            }
        }

        // Drag and drop events
        const dropzone = document.getElementById('cvDropzone');
        if (dropzone) {
            ['dragenter', 'dragover'].forEach(eventName => {
                dropzone.addEventListener(eventName, (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    dropzone.classList.add('dragover');
                }, false);
            });

            ['dragleave', 'drop'].forEach(eventName => {
                dropzone.addEventListener(eventName, (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    dropzone.classList.remove('dragover');
                }, false);
            });
        }
    </script>
</body>
</html>
