<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/company_verification.php';

if (!isset($_SESSION['admin_username'])) {
    header('Location: admin_login.php');
    exit();
}

global $con;

$admin_username = $_SESSION['admin_username'] ?? 'Admin';
$admin_id = $_SESSION['admin_id'] ?? 0;
if (!$admin_id) {
    $aq = mysqli_query($con, "SELECT id FROM admin_login WHERE admin_user_name = '" . mysqli_real_escape_string($con, $admin_username) . "' LIMIT 1");
    if ($aq && $arow = mysqli_fetch_assoc($aq)) {
        $admin_id = (int)$arow['id'];
        $_SESSION['admin_id'] = $admin_id;
    }
}

$company_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($company_id <= 0) {
    header('Location: company_verifications.php');
    exit();
}

// Fetch complete company record
$stmt = mysqli_prepare($con, "SELECT * FROM companies WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $company_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$company = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$company) {
    header('Location: company_verifications.php');
    exit();
}

$feedback = null;

// Handle Review Decision Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf)) {
        $feedback = ['ok' => false, 'error' => 'Security token expired. Please reload and try again.'];
    } else {
        $action = trim(strip_tags($_POST['review_action'] ?? ''));
        $reason = trim(strip_tags($_POST['reason'] ?? ''));
        $notes  = trim(strip_tags($_POST['notes'] ?? ''));

        if (in_array($action, ['approve', 'reject', 'request_resubmission'])) {
            $result = nh_admin_review_company($con, $admin_id, $company_id, $action, $reason, $notes);
            $feedback = $result;

            // Refresh company data
            $stmt = mysqli_prepare($con, "SELECT * FROM companies WHERE id = ? LIMIT 1");
            mysqli_stmt_bind_param($stmt, "i", $company_id);
            mysqli_stmt_execute($stmt);
            $company = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
        } else {
            $feedback = ['ok' => false, 'error' => 'Invalid review action requested.'];
        }
    }
}

$documents = nh_get_company_documents($con, $company_id);
$history   = nh_get_verification_history($con, $company_id);
$status_info = nh_verification_status_info($company['verification_status']);

include 'header.php';
?>

<style>
    .cvd-wrap { padding: 0 0 60px; }
    .cvd-hero {
        position: relative;
        margin-top: -72px;
        padding: 96px 0 84px;
        background: linear-gradient(120deg, #1a56db 0%, #0ea5e9 55%, #0284c7 120%);
        overflow: hidden;
    }
    .cvd-hero::before, .cvd-hero::after {
        content: '';
        position: absolute;
        border-radius: 50%;
        pointer-events: none;
    }
    .cvd-hero::before { top: -120px; right: -60px; width: 360px; height: 360px; background: radial-gradient(circle, rgba(255,255,255,0.14) 0%, transparent 70%); }
    .cvd-hero::after { bottom: -140px; left: 12%; width: 320px; height: 320px; background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%); }
    .cvd-hero-inner { position: relative; z-index: 2; display: flex; align-items: flex-end; justify-content: space-between; flex-wrap: wrap; gap: 18px; }
    .cvd-hero h1 { color: #fff; font-size: 1.85rem; font-weight: 800; letter-spacing: -0.5px; margin: 0 0 6px; }
    .cvd-hero .cvd-hero-sub { color: rgba(255,255,255,0.85); margin: 0; font-size: .94rem; }

    .cvd-back-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        border-radius: 999px;
        background: rgba(255,255,255,0.18);
        border: 1px solid rgba(255,255,255,0.3);
        color: #fff !important;
        font-weight: 700;
        font-size: 0.82rem;
        text-decoration: none;
        transition: background .2s;
        margin-bottom: 14px;
    }
    .cvd-back-btn:hover { background: rgba(255,255,255,0.28); color: #fff; text-decoration: none; }

    .cvd-grid {
        display: grid;
        grid-template-columns: 2fr 1.25fr;
        gap: 24px;
        margin-top: -36px;
        position: relative;
        z-index: 10;
    }
    @media (max-width: 991px) {
        .cvd-grid { grid-template-columns: 1fr; }
    }

    .cvd-card {
        background: var(--bg-card, #fff);
        border: 1px solid var(--border-light, #e2e8f0);
        border-radius: 18px;
        box-shadow: 0 10px 25px -5px rgba(15,23,42,0.04);
        padding: 24px;
        margin-bottom: 24px;
    }
    .cvd-card-title {
        font-size: 1.05rem;
        font-weight: 800;
        color: var(--text, #1e293b);
        margin: 0 0 16px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .cvd-card-title i { color: var(--primary, #1a56db); font-size: 1rem; }

    /* Company Info Profile */
    .cvd-company-header {
        display: flex;
        align-items: center;
        gap: 18px;
        padding-bottom: 18px;
        border-bottom: 1px solid var(--border-light, #e2e8f0);
        margin-bottom: 18px;
    }
    .cvd-logo-large {
        width: 68px;
        height: 68px;
        border-radius: 16px;
        background: linear-gradient(135deg, #e0e7ff, #c7d2fe);
        color: #3730a3;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        font-size: 1.6rem;
        border: 1px solid rgba(99,102,241,0.25);
        flex-shrink: 0;
        overflow: hidden;
    }
    .cvd-logo-large img { width: 100%; height: 100%; object-fit: contain; }

    .cvd-meta-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 14px;
    }
    .cvd-meta-item small { font-size: 0.72rem; text-transform: uppercase; font-weight: 800; letter-spacing: .5px; color: var(--text-muted, #64748b); display: block; }
    .cvd-meta-item p { font-size: 0.9rem; font-weight: 600; color: var(--text, #1e293b); margin: 2px 0 0; word-break: break-word; }

    /* Documents List */
    .cvd-doc-item {
        border: 1px solid var(--border-light, #e2e8f0);
        border-radius: 14px;
        padding: 16px 18px;
        margin-bottom: 14px;
        background: var(--bg, #f8fafc);
        transition: border-color .2s;
    }
    .cvd-doc-item:hover { border-color: var(--primary, #1a56db); }
    .cvd-doc-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 10px; }
    .cvd-doc-badge {
        font-size: 0.75rem;
        font-weight: 700;
        padding: 3px 8px;
        border-radius: 999px;
    }
    .cvd-doc-details {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 10px;
        font-size: 0.8rem;
        color: var(--text-muted, #64748b);
        margin-bottom: 12px;
        background: #fff;
        padding: 10px 14px;
        border-radius: 10px;
        border: 1px solid var(--border-light, #e2e8f0);
    }
    .cvd-doc-details strong { color: var(--text, #1e293b); }
    .cvd-doc-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .cvd-btn-doc {
        padding: 6px 12px;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 700;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all .2s;
    }
    .cvd-btn-view { background: #3b82f6; color: #fff !important; }
    .cvd-btn-view:hover { background: #2563eb; }
    .cvd-btn-download { background: #f1f5f9; color: #334155 !important; border: 1px solid #cbd5e1; }
    .cvd-btn-download:hover { background: #e2e8f0; }

    /* Timeline */
    .cvd-timeline { position: relative; padding-left: 20px; }
    .cvd-timeline::before {
        content: '';
        position: absolute;
        top: 6px;
        bottom: 6px;
        left: 6px;
        width: 2px;
        background: var(--border-light, #e2e8f0);
    }
    .cvd-time-item { position: relative; margin-bottom: 18px; }
    .cvd-time-dot {
        position: absolute;
        left: -20px;
        top: 4px;
        width: 14px;
        height: 14px;
        border-radius: 50%;
        background: var(--primary, #1a56db);
        border: 3px solid #fff;
        box-shadow: 0 0 0 1px rgba(26,86,219,0.3);
    }
    .cvd-time-head { font-size: 0.85rem; font-weight: 700; color: var(--text, #1e293b); }
    .cvd-time-date { font-size: 0.72rem; color: var(--text-muted, #64748b); }
    .cvd-time-desc { font-size: 0.8rem; color: #475569; margin-top: 2px; }

    /* Action Forms */
    .cvd-action-box {
        background: var(--bg-card, #fff);
        border: 2px solid var(--border-light, #e2e8f0);
        border-radius: 18px;
        padding: 22px;
        margin-bottom: 24px;
    }
    .cvd-action-tab {
        display: flex;
        border-bottom: 1px solid var(--border-light, #e2e8f0);
        margin-bottom: 18px;
        gap: 4px;
    }
    .cvd-atab-btn {
        padding: 8px 14px;
        border: none;
        background: transparent;
        font-weight: 700;
        font-size: 0.84rem;
        cursor: pointer;
        color: var(--text-muted, #64748b);
        border-bottom: 2px solid transparent;
        transition: all .2s;
    }
    .cvd-atab-btn.active { color: var(--primary, #1a56db); border-bottom-color: var(--primary, #1a56db); }

    .cvd-form-group { margin-bottom: 14px; }
    .cvd-form-group label { font-size: 0.8rem; font-weight: 700; color: var(--text, #1e293b); display: block; margin-bottom: 6px; }
    .cvd-input, .cvd-textarea {
        width: 100%;
        border: 1.5px solid var(--border-light, #e2e8f0);
        border-radius: 10px;
        padding: 10px 14px;
        font-size: 0.86rem;
        color: var(--text, #1e293b);
        background: #fff;
        outline: none;
    }
    .cvd-input:focus, .cvd-textarea:focus { border-color: var(--primary, #1a56db); }

    .cvd-btn-approve {
        width: 100%;
        background: linear-gradient(135deg, #059669, #10b981);
        color: #fff;
        border: none;
        border-radius: 12px;
        padding: 12px;
        font-weight: 800;
        font-size: 0.95rem;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        box-shadow: 0 4px 14px rgba(5,150,105,0.3);
    }
    .cvd-btn-reject {
        width: 100%;
        background: linear-gradient(135deg, #dc2626, #ef4444);
        color: #fff;
        border: none;
        border-radius: 12px;
        padding: 12px;
        font-weight: 800;
        font-size: 0.95rem;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        box-shadow: 0 4px 14px rgba(220,38,38,0.3);
    }
    .cvd-btn-resubmit {
        width: 100%;
        background: linear-gradient(135deg, #0284c7, #38bdf8);
        color: #fff;
        border: none;
        border-radius: 12px;
        padding: 12px;
        font-weight: 800;
        font-size: 0.95rem;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        box-shadow: 0 4px 14px rgba(2,132,199,0.3);
    }
</style>

<div class="cvd-wrap">
    <div class="cvd-hero">
        <div class="container cvd-hero-inner">
            <div>
                <a href="company_verifications.php" class="cvd-back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Verification Requests
                </a>
                <h1><i class="fas fa-shield-halved"></i> Review Verification Evidence</h1>
                <p class="cvd-hero-sub">Evaluating business credentials for <strong><?php echo htmlspecialchars($company['company_name']); ?></strong>.</p>
            </div>
        </div>
    </div>

    <div class="container">
        <?php if (!empty($feedback)): ?>
            <div class="alert <?php echo $feedback['ok'] ? 'alert-success' : 'alert-danger'; ?> alert-dismissible fade show mt-3 mb-4" role="alert" style="border-radius:14px;font-weight:600;">
                <i class="fas <?php echo $feedback['ok'] ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?> mr-2"></i>
                <?php echo htmlspecialchars($feedback['ok'] ? $feedback['msg'] : $feedback['error']); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>

        <div class="cvd-grid">
            <!-- Left Main Column -->
            <div>
                <!-- Company Profile Overview Card -->
                <div class="cvd-card">
                    <div class="cvd-company-header">
                        <div class="cvd-logo-large">
                            <?php if (!empty($company['logo']) && file_exists(__DIR__ . '/../uploads/company_logos/' . $company['logo'])): ?>
                                <img src="../uploads/company_logos/<?php echo htmlspecialchars($company['logo']); ?>" alt="logo">
                            <?php else: ?>
                                <?php echo strtoupper(substr($company['company_name'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div style="font-size:1.35rem;font-weight:800;color:var(--text, #1e293b);display:flex;align-items:center;gap:8px;">
                                <?php echo htmlspecialchars($company['company_name']); ?>
                                <?php if ($company['verification_status'] === 'verified'): ?>
                                    <i class="fas fa-certificate text-warning" title="Verified Employer" style="font-size:1.1rem;"></i>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:0.85rem;color:var(--text-muted, #64748b);margin-top:2px;">
                                <i class="fas fa-calendar-alt mr-1"></i> Registered on <?php echo date('M d, Y', strtotime($company['registration_date'] ?? 'now')); ?>
                            </div>
                        </div>
                    </div>

                    <div class="cvd-meta-grid">
                        <div class="cvd-meta-item">
                            <small>Corporate Email</small>
                            <p><i class="fas fa-envelope mr-1 text-primary"></i><?php echo htmlspecialchars($company['company_email']); ?></p>
                        </div>
                        <div class="cvd-meta-item">
                            <small>Phone / Hotline</small>
                            <p><i class="fas fa-phone mr-1 text-primary"></i><?php echo !empty($company['company_phone']) ? htmlspecialchars($company['company_phone']) : '—'; ?></p>
                        </div>
                        <div class="cvd-meta-item">
                            <small>Industry</small>
                            <p><i class="fas fa-industry mr-1 text-primary"></i><?php echo !empty($company['industry']) ? htmlspecialchars($company['industry']) : '—'; ?></p>
                        </div>
                        <div class="cvd-meta-item">
                            <small>Company Size</small>
                            <p><i class="fas fa-users mr-1 text-primary"></i><?php echo !empty($company['company_size']) ? htmlspecialchars($company['company_size']) : '—'; ?></p>
                        </div>
                        <div class="cvd-meta-item">
                            <small>Website</small>
                            <p>
                                <?php if (!empty($company['company_website'])): ?>
                                    <a href="<?php echo htmlspecialchars($company['company_website']); ?>" target="_blank" rel="noopener noreferrer">
                                        <i class="fas fa-globe mr-1"></i><?php echo htmlspecialchars($company['company_website']); ?>
                                    </a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="cvd-meta-item">
                            <small>Headquarters Address</small>
                            <p><i class="fas fa-map-marker-alt mr-1 text-primary"></i><?php echo !empty($company['address']) ? htmlspecialchars($company['address']) : '—'; ?></p>
                        </div>
                    </div>

                    <?php if (!empty($company['description'])): ?>
                        <div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--border-light, #e2e8f0);">
                            <small style="font-size:0.72rem;text-transform:uppercase;font-weight:800;letter-spacing:.5px;color:var(--text-muted, #64748b);display:block;margin-bottom:4px;">About Company</small>
                            <p style="font-size:0.86rem;line-height:1.6;color:#475569;margin:0;"><?php echo nl2br(htmlspecialchars($company['description'])); ?></p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Submitted Evidence Documents -->
                <div class="cvd-card">
                    <h5 class="cvd-card-title">
                        <i class="fas fa-folder-open"></i> Submitted Business Evidence (<?php echo count($documents); ?>)
                    </h5>

                    <?php if (!empty($documents)): ?>
                        <?php foreach ($documents as $doc): ?>
                            <div class="cvd-doc-item">
                                <div class="cvd-doc-head">
                                    <div>
                                        <div style="font-weight:800;font-size:1rem;color:var(--text, #1e293b);display:flex;align-items:center;gap:6px;">
                                            <i class="fas fa-file-contract text-primary"></i>
                                            <?php echo htmlspecialchars($doc['document_title']); ?>
                                        </div>
                                        <span class="badge badge-secondary" style="font-size:0.74rem;margin-top:4px;">
                                            <?php echo htmlspecialchars(nh_verification_document_type_label($doc['document_type'])); ?>
                                        </span>
                                    </div>
                                    <div>
                                        <span class="badge <?php 
                                            echo $doc['verification_status'] === 'approved' ? 'badge-success' : ($doc['verification_status'] === 'rejected' ? 'badge-danger' : 'badge-warning'); 
                                        ?>" style="padding:6px 12px;font-size:0.75rem;text-transform:capitalize;">
                                            <?php echo htmlspecialchars($doc['verification_status']); ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="cvd-doc-details">
                                    <div>
                                        <span>Document / License No:</span><br>
                                        <strong><?php echo !empty($doc['document_number']) ? htmlspecialchars($doc['document_number']) : 'Not provided'; ?></strong>
                                    </div>
                                    <div>
                                        <span>Issuing Authority:</span><br>
                                        <strong><?php echo !empty($doc['issuing_authority']) ? htmlspecialchars($doc['issuing_authority']) : 'Not provided'; ?></strong>
                                    </div>
                                    <div>
                                        <span>Issue / Expiry:</span><br>
                                        <strong><?php echo !empty($doc['issue_date']) ? $doc['issue_date'] : '—'; ?> / <?php echo !empty($doc['expiry_date']) ? $doc['expiry_date'] : '—'; ?></strong>
                                    </div>
                                    <div>
                                        <span>File Specs:</span><br>
                                        <strong><?php echo strtoupper(pathinfo($doc['original_filename'], PATHINFO_EXTENSION)); ?> (<?php echo round($doc['file_size'] / 1024, 1); ?> KB)</strong>
                                    </div>
                                </div>

                                <?php if (!empty($doc['admin_note'])): ?>
                                    <div class="alert alert-light border mb-3 py-2 px-3" style="font-size:0.82rem;">
                                        <strong><i class="fas fa-comment-dots mr-1"></i> Admin Note:</strong> <?php echo htmlspecialchars($doc['admin_note']); ?>
                                    </div>
                                <?php endif; ?>

                                <div class="cvd-doc-actions">
                                    <a href="../api/serve_verification_document.php?id=<?php echo $doc['id']; ?>" target="_blank" rel="noopener noreferrer" class="cvd-btn-doc cvd-btn-view">
                                        <i class="fas fa-eye"></i> View Document
                                    </a>
                                    <a href="../api/serve_verification_document.php?id=<?php echo $doc['id']; ?>&download=1" class="cvd-btn-doc cvd-btn-download">
                                        <i class="fas fa-download"></i> Download
                                    </a>
                                    <span class="text-muted ml-auto" style="font-size:0.75rem;">
                                        Uploaded <?php echo date('M d, Y h:i A', strtotime($doc['created_at'])); ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-file-circle-xmark fa-2x mb-2 text-secondary"></i>
                            <p style="font-size:0.9rem;margin:0;">No business evidence has been uploaded by this company yet.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Audit History -->
                <div class="cvd-card">
                    <h5 class="cvd-card-title">
                        <i class="fas fa-clock-rotate-left"></i> Verification Audit Trail
                    </h5>
                    <?php if (!empty($history)): ?>
                        <div class="cvd-timeline">
                            <?php foreach ($history as $h): ?>
                                <div class="cvd-time-item">
                                    <div class="cvd-time-dot"></div>
                                    <div class="cvd-time-head">
                                        Action: <span style="text-transform:capitalize;"><?php echo str_replace('_', ' ', htmlspecialchars($h['action'])); ?></span>
                                        <?php if (!empty($h['admin_user_name'])): ?>
                                            by <strong><?php echo htmlspecialchars($h['admin_user_name']); ?></strong>
                                        <?php endif; ?>
                                    </div>
                                    <div class="cvd-time-date">
                                        <?php echo date('M d, Y · h:i A', strtotime($h['created_at'])); ?>
                                        · Status: <code><?php echo htmlspecialchars($h['previous_status'] ?? 'none'); ?></code> &rarr; <code><?php echo htmlspecialchars($h['new_status'] ?? 'none'); ?></code>
                                    </div>
                                    <?php if (!empty($h['reason'])): ?>
                                        <div class="cvd-time-desc">
                                            <em>&ldquo;<?php echo htmlspecialchars($h['reason']); ?>&rdquo;</em>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted" style="font-size:0.85rem;margin:0;">No audit events recorded yet.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Sidebar Column -->
            <div>
                <!-- Current Verification Status Card -->
                <div class="cvd-card" style="border-top: 4px solid <?php echo $status_info['color']; ?>;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                        <span style="font-size:0.75rem;text-transform:uppercase;font-weight:800;letter-spacing:.5px;color:var(--text-muted, #64748b);">Current State</span>
                        <span class="badge" style="background:<?php echo $status_info['bg']; ?>;color:<?php echo $status_info['color']; ?>;font-weight:700;padding:6px 12px;border-radius:999px;">
                            <i class="fas <?php echo $status_info['icon']; ?> mr-1"></i><?php echo $status_info['label']; ?>
                        </span>
                    </div>

                    <h4 style="font-weight:800;color:var(--text, #1e293b);margin:0 0 6px;">
                        <?php echo $status_info['title']; ?>
                    </h4>
                    <p style="font-size:0.84rem;color:#475569;margin-bottom:14px;">
                        <?php echo $status_info['desc']; ?>
                    </p>

                    <?php if ($company['verification_status'] === 'verified' && !empty($company['verified_at'])): ?>
                        <div class="alert alert-success py-2 px-3" style="font-size:0.8rem;border-radius:10px;">
                            <i class="fas fa-check-circle mr-1"></i> Verified on <?php echo date('M d, Y · h:i A', strtotime($company['verified_at'])); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($company['verification_rejection_reason'])): ?>
                        <div class="alert alert-danger py-2 px-3" style="font-size:0.8rem;border-radius:10px;">
                            <strong>Latest Reason / Feedback:</strong><br>
                            <?php echo htmlspecialchars($company['verification_rejection_reason']); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Admin Action Box -->
                <div class="cvd-action-box">
                    <h5 style="font-weight:800;font-size:1.02rem;margin:0 0 14px;color:var(--text, #1e293b);display:flex;align-items:center;gap:8px;">
                        <i class="fas fa-gavel text-primary"></i> Take Review Decision
                    </h5>

                    <!-- Action Tabs -->
                    <div class="cvd-action-tab">
                        <button type="button" class="cvd-atab-btn active" onclick="switchReviewTab('approve')">
                            <i class="fas fa-check-circle text-success mr-1"></i> Approve
                        </button>
                        <button type="button" class="cvd-atab-btn" onclick="switchReviewTab('resubmit')">
                            <i class="fas fa-rotate text-info mr-1"></i> Resubmit
                        </button>
                        <button type="button" class="cvd-atab-btn" onclick="switchReviewTab('reject')">
                            <i class="fas fa-times-circle text-danger mr-1"></i> Reject
                        </button>
                    </div>

                    <!-- Approve Form -->
                    <form method="POST" action="" id="formApprove" onsubmit="return confirm('Approve verification for this company? This will instantly unlock full job posting and recruitment features.');">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="review_action" value="approve">

                        <p style="font-size:0.84rem;color:#475569;margin-bottom:14px;">
                            Approving will mark this company as <strong>Verified</strong>, award the Verified Company badge, and grant immediate access to job posting and applicant hiring.
                        </p>

                        <div class="cvd-form-group">
                            <label>Approval Note (Optional)</label>
                            <input type="text" name="notes" class="cvd-input" placeholder="e.g. Valid trade license verified with Dhaka North City Corporation">
                        </div>

                        <button type="submit" class="cvd-btn-approve">
                            <i class="fas fa-circle-check"></i> Approve Company Verification
                        </button>
                    </form>

                    <!-- Resubmit Form -->
                    <form method="POST" action="" id="formResubmit" style="display:none;" onsubmit="return confirm('Request document resubmission from this company?');">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="review_action" value="request_resubmission">

                        <p style="font-size:0.84rem;color:#475569;margin-bottom:14px;">
                            Request the employer to upload corrected or additional business evidence (e.g. higher-resolution scan, missing tax certificate, or renewed license).
                        </p>

                        <div class="cvd-form-group">
                            <label>Instructions to Employer <span class="text-danger">*</span></label>
                            <textarea name="reason" class="cvd-textarea" rows="4" required placeholder="Describe specifically what needs to be corrected or re-uploaded..."></textarea>
                        </div>

                        <button type="submit" class="cvd-btn-resubmit">
                            <i class="fas fa-arrows-rotate"></i> Request Resubmission
                        </button>
                    </form>

                    <!-- Reject Form -->
                    <form method="POST" action="" id="formReject" style="display:none;" onsubmit="return confirm('Reject this company verification request? Recruitment access will remain restricted.');">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="review_action" value="reject">

                        <p style="font-size:0.84rem;color:#dc2626;margin-bottom:14px;">
                            Rejecting will decline the verification request. Recruitment functions remain locked until new evidence is submitted and approved.
                        </p>

                        <div class="cvd-form-group">
                            <label>Rejection Reason <span class="text-danger">*</span></label>
                            <textarea name="reason" class="cvd-textarea" rows="4" required placeholder="Explain why the submitted evidence was rejected (e.g. Invalid / expired document, forged registration)..."></textarea>
                        </div>

                        <button type="submit" class="cvd-btn-reject">
                            <i class="fas fa-circle-xmark"></i> Reject Verification Request
                        </button>
                    </form>
                </div>

                <!-- Verification Guidance Card -->
                <div class="cvd-card" style="background:#f8fafc;">
                    <h6 style="font-weight:800;font-size:0.88rem;color:var(--text, #1e293b);margin-bottom:8px;">
                        <i class="fas fa-shield mr-1 text-primary"></i> Verification Guidelines
                    </h6>
                    <ul style="padding-left:18px;margin:0;font-size:0.8rem;color:#475569;line-height:1.6;">
                        <li>Verify document legitimacy against official registry records.</li>
                        <li>Ensure expiration date has not passed.</li>
                        <li>Check that business name matches registered company name.</li>
                        <li>All decisions are permanently recorded in the audit trail.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function switchReviewTab(tab) {
    document.querySelectorAll('.cvd-atab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('formApprove').style.display = 'none';
    document.getElementById('formResubmit').style.display = 'none';
    document.getElementById('formReject').style.display = 'none';

    if (tab === 'approve') {
        event.currentTarget.classList.add('active');
        document.getElementById('formApprove').style.display = 'block';
    } else if (tab === 'resubmit') {
        event.currentTarget.classList.add('active');
        document.getElementById('formResubmit').style.display = 'block';
    } else if (tab === 'reject') {
        event.currentTarget.classList.add('active');
        document.getElementById('formReject').style.display = 'block';
    }
}
</script>

</body>
</html>
