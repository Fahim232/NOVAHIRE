<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/company_verification.php';

if (!isset($_SESSION['admin_username'])) {
    header('Location: admin_login.php');
    exit();
}

global $con;

$status_filter = isset($_GET['status']) ? trim(strip_tags($_GET['status'])) : 'all';
$search = isset($_GET['search']) ? trim(strip_tags($_GET['search'])) : '';

$stats = nh_admin_get_verification_stats($con);
$requests = nh_admin_get_verification_requests($con, $status_filter, $search);

include 'header.php';
?>

<style>
    .cv-wrap { padding: 0 0 60px; }
    .cv-hero {
        position: relative;
        margin-top: -72px;
        padding: 96px 0 84px;
        background: linear-gradient(120deg, #1a56db 0%, #0ea5e9 55%, #0284c7 120%);
        overflow: hidden;
    }
    .cv-hero::before, .cv-hero::after {
        content: '';
        position: absolute;
        border-radius: 50%;
        pointer-events: none;
    }
    .cv-hero::before { top: -120px; right: -60px; width: 360px; height: 360px; background: radial-gradient(circle, rgba(255,255,255,0.14) 0%, transparent 70%); }
    .cv-hero::after { bottom: -140px; left: 12%; width: 320px; height: 320px; background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%); }
    .cv-hero-inner { position: relative; z-index: 2; display: flex; align-items: flex-end; justify-content: space-between; flex-wrap: wrap; gap: 18px; }
    .cv-hero h1 { color: #fff; font-size: 2rem; font-weight: 800; letter-spacing: -0.5px; margin: 0 0 6px; }
    .cv-hero h1 i { font-size: 1.4rem; margin-right: 8px; opacity: .9; }
    .cv-hero .cv-hero-sub { color: rgba(255,255,255,0.85); margin: 0; font-size: .96rem; }

    /* Stat Cards */
    .cv-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-top: -38px; position: relative; z-index: 10; margin-bottom: 28px; }
    .cv-stat-card {
        background: var(--bg-card, #fff);
        border: 1px solid var(--border-light, #e2e8f0);
        border-radius: 16px;
        padding: 18px 20px;
        box-shadow: 0 10px 25px -5px rgba(15,23,42,0.06);
        display: flex;
        align-items: center;
        gap: 16px;
        text-decoration: none;
        color: inherit;
        transition: transform .25s ease, box-shadow .25s ease;
    }
    .cv-stat-card:hover { transform: translateY(-3px); box-shadow: 0 14px 30px -5px rgba(15,23,42,0.12); color: inherit; }
    .cv-stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        flex-shrink: 0;
    }
    .cv-stat-num { font-size: 1.6rem; font-weight: 800; line-height: 1.1; color: var(--text, #1e293b); }
    .cv-stat-lbl { font-size: 0.76rem; font-weight: 700; color: var(--text-muted, #64748b); text-transform: uppercase; letter-spacing: .5px; margin-top: 2px; }

    /* Filter & Search Bar */
    .cv-toolbar {
        background: var(--bg-card, #fff);
        border: 1px solid var(--border-light, #e2e8f0);
        border-radius: 16px;
        padding: 14px 18px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 14px;
        box-shadow: 0 4px 15px -3px rgba(15,23,42,0.03);
    }
    .cv-tabs { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .cv-tab {
        padding: 7px 14px;
        border-radius: 999px;
        font-size: 0.82rem;
        font-weight: 700;
        text-decoration: none;
        color: var(--text-muted, #64748b);
        transition: all .2s;
        border: 1px solid transparent;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .cv-tab:hover { color: var(--primary, #1a56db); background: rgba(26,86,219,0.06); }
    .cv-tab.active {
        background: linear-gradient(135deg, #1a56db, #0ea5e9);
        color: #fff;
        box-shadow: 0 4px 12px rgba(26,86,219,0.25);
    }
    .cv-tab-count {
        background: rgba(255,255,255,0.25);
        color: inherit;
        font-size: 0.7rem;
        padding: 1px 6px;
        border-radius: 999px;
    }
    .cv-tab:not(.active) .cv-tab-count {
        background: rgba(0,0,0,0.06);
        color: var(--text-muted, #64748b);
    }

    .cv-search-form { display: flex; align-items: center; gap: 8px; flex: 1; max-width: 380px; }
    .cv-search-input {
        width: 100%;
        border: 1.5px solid var(--border-light, #e2e8f0);
        background: var(--bg, #f8fafc);
        border-radius: 12px;
        padding: 8px 14px;
        font-size: 0.85rem;
        color: var(--text, #1e293b);
        outline: none;
        transition: border-color .2s;
    }
    .cv-search-input:focus { border-color: var(--primary, #1a56db); background: #fff; }
    .cv-search-btn {
        background: linear-gradient(135deg, #1a56db, #0ea5e9);
        color: #fff;
        border: none;
        border-radius: 12px;
        padding: 8px 16px;
        font-size: 0.85rem;
        font-weight: 700;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    /* Table Container */
    .cv-card {
        background: var(--bg-card, #fff);
        border: 1px solid var(--border-light, #e2e8f0);
        border-radius: 18px;
        box-shadow: 0 10px 25px -5px rgba(15,23,42,0.04);
        overflow: hidden;
    }
    .cv-table { width: 100%; border-collapse: separate; border-spacing: 0; }
    .cv-table th {
        background: rgba(248, 250, 252, 0.8);
        padding: 14px 18px;
        font-size: 0.74rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .6px;
        color: var(--text-muted, #64748b);
        border-bottom: 1px solid var(--border-light, #e2e8f0);
        text-align: left;
    }
    .cv-table td {
        padding: 16px 18px;
        border-bottom: 1px solid var(--border-light, #e2e8f0);
        vertical-align: middle;
        font-size: 0.88rem;
        color: var(--text, #1e293b);
    }
    .cv-table tr:last-child td { border-bottom: none; }
    .cv-table tr:hover td { background: rgba(26,86,219,0.02); }

    .cv-company-cell { display: flex; align-items: center; gap: 12px; }
    .cv-company-logo {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        background: linear-gradient(135deg, #e0e7ff, #c7d2fe);
        color: #3730a3;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        font-size: 1rem;
        border: 1px solid rgba(99,102,241,0.2);
        flex-shrink: 0;
        overflow: hidden;
    }
    .cv-company-logo img { width: 100%; height: 100%; object-fit: contain; }
    .cv-company-name { font-weight: 700; color: var(--text, #1e293b); font-size: 0.94rem; display: flex; align-items: center; gap: 6px; }
    .cv-company-email { font-size: 0.78rem; color: var(--text-muted, #64748b); margin-top: 1px; }

    .cv-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 0.75rem;
        font-weight: 700;
    }
    .cv-badge-under_review { background: rgba(217, 119, 6, 0.12); color: #d97706; }
    .cv-badge-verified { background: rgba(5, 150, 105, 0.12); color: #059669; }
    .cv-badge-resubmission_required { background: rgba(2, 132, 199, 0.12); color: #0284c7; }
    .cv-badge-pending { background: rgba(202, 138, 4, 0.12); color: #b45309; }
    .cv-badge-rejected { background: rgba(220, 38, 38, 0.12); color: #dc2626; }

    .cv-btn-review {
        background: linear-gradient(135deg, #1a56db, #0ea5e9);
        color: #fff !important;
        font-weight: 700;
        font-size: 0.8rem;
        padding: 8px 14px;
        border-radius: 10px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: transform .2s, box-shadow .2s;
        box-shadow: 0 4px 12px rgba(26,86,219,0.2);
    }
    .cv-btn-review:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(26,86,219,0.3); }

    .cv-empty { text-align: center; padding: 60px 20px; }
    .cv-empty-icon { font-size: 3rem; color: #cbd5e1; margin-bottom: 12px; }
</style>

<div class="cv-wrap">
    <!-- Hero Banner -->
    <div class="cv-hero">
        <div class="container cv-hero-inner">
            <div>
                <h1><i class="fas fa-building-circle-check"></i> Company Verification Requests</h1>
                <p class="cv-hero-sub">Review Trade Licenses and business evidence submitted by employers to unlock recruitment access.</p>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Quick Stats -->
        <div class="cv-stats">
            <a href="company_verifications.php?status=under_review" class="cv-stat-card">
                <div class="cv-stat-icon" style="background:rgba(217,119,6,0.12);color:#d97706;">
                    <i class="fas fa-clock"></i>
                </div>
                <div>
                    <div class="cv-stat-num"><?php echo $stats['under_review']; ?></div>
                    <div class="cv-stat-lbl">Under Review</div>
                </div>
            </a>
            <a href="company_verifications.php?status=resubmission_required" class="cv-stat-card">
                <div class="cv-stat-icon" style="background:rgba(2,132,199,0.12);color:#0284c7;">
                    <i class="fas fa-arrows-rotate"></i>
                </div>
                <div>
                    <div class="cv-stat-num"><?php echo $stats['resubmission']; ?></div>
                    <div class="cv-stat-lbl">Resubmissions</div>
                </div>
            </a>
            <a href="company_verifications.php?status=pending" class="cv-stat-card">
                <div class="cv-stat-icon" style="background:rgba(202,138,4,0.12);color:#ca8a04;">
                    <i class="fas fa-hourglass-start"></i>
                </div>
                <div>
                    <div class="cv-stat-num"><?php echo $stats['pending']; ?></div>
                    <div class="cv-stat-lbl">Awaiting Evidence</div>
                </div>
            </a>
            <a href="company_verifications.php?status=verified" class="cv-stat-card">
                <div class="cv-stat-icon" style="background:rgba(5,150,105,0.12);color:#059669;">
                    <i class="fas fa-circle-check"></i>
                </div>
                <div>
                    <div class="cv-stat-num"><?php echo $stats['verified']; ?></div>
                    <div class="cv-stat-lbl">Verified Companies</div>
                </div>
            </a>
        </div>

        <!-- Filter and Search Toolbar -->
        <div class="cv-toolbar">
            <div class="cv-tabs">
                <a href="company_verifications.php?status=all<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="cv-tab <?php echo $status_filter === 'all' ? 'active' : ''; ?>">
                    All <span class="cv-tab-count"><?php echo array_sum(array_slice($stats, 0, 5)); ?></span>
                </a>
                <a href="company_verifications.php?status=under_review<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="cv-tab <?php echo $status_filter === 'under_review' ? 'active' : ''; ?>">
                    <i class="fas fa-clock"></i> Under Review <span class="cv-tab-count"><?php echo $stats['under_review']; ?></span>
                </a>
                <a href="company_verifications.php?status=resubmission_required<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="cv-tab <?php echo $status_filter === 'resubmission_required' ? 'active' : ''; ?>">
                    <i class="fas fa-arrows-rotate"></i> Resubmission <span class="cv-tab-count"><?php echo $stats['resubmission']; ?></span>
                </a>
                <a href="company_verifications.php?status=pending<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="cv-tab <?php echo $status_filter === 'pending' ? 'active' : ''; ?>">
                    <i class="fas fa-hourglass-start"></i> Pending <span class="cv-tab-count"><?php echo $stats['pending']; ?></span>
                </a>
                <a href="company_verifications.php?status=verified<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="cv-tab <?php echo $status_filter === 'verified' ? 'active' : ''; ?>">
                    <i class="fas fa-circle-check"></i> Verified <span class="cv-tab-count"><?php echo $stats['verified']; ?></span>
                </a>
                <a href="company_verifications.php?status=rejected<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="cv-tab <?php echo $status_filter === 'rejected' ? 'active' : ''; ?>">
                    <i class="fas fa-circle-xmark"></i> Rejected <span class="cv-tab-count"><?php echo $stats['rejected']; ?></span>
                </a>
            </div>

            <form method="GET" action="company_verifications.php" class="cv-search-form">
                <?php if ($status_filter !== 'all'): ?>
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                <?php endif; ?>
                <input type="text" name="search" class="cv-search-input" placeholder="Search company or email..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="cv-search-btn"><i class="fas fa-search"></i> Search</button>
                <?php if (!empty($search)): ?>
                    <a href="company_verifications.php?status=<?php echo htmlspecialchars($status_filter); ?>" class="btn btn-sm btn-outline-secondary" title="Clear Search"><i class="fas fa-times"></i></a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Requests Table -->
        <div class="cv-card">
            <?php if (!empty($requests)): ?>
                <div class="table-responsive">
                    <table class="cv-table">
                        <thead>
                            <tr>
                                <th>Company</th>
                                <th>Verification Status</th>
                                <th>Submitted Documents</th>
                                <th>Registered</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $req): 
                                $status_info = nh_verification_status_info($req['verification_status']);
                            ?>
                                <tr>
                                    <td>
                                        <div class="cv-company-cell">
                                            <div class="cv-company-logo">
                                                <?php if (!empty($req['logo']) && file_exists(__DIR__ . '/../uploads/company_logos/' . $req['logo'])): ?>
                                                    <img src="../uploads/company_logos/<?php echo htmlspecialchars($req['logo']); ?>" alt="logo">
                                                <?php else: ?>
                                                    <?php echo strtoupper(substr($req['company_name'], 0, 1)); ?>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <div class="cv-company-name">
                                                    <?php echo htmlspecialchars($req['company_name']); ?>
                                                    <?php if ($req['verification_status'] === 'verified'): ?>
                                                        <i class="fas fa-certificate text-warning" title="Verified" style="font-size:0.8rem;"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="cv-company-email">
                                                    <i class="fas fa-envelope mr-1 opacity-75"></i><?php echo htmlspecialchars($req['company_email']); ?>
                                                    <?php if (!empty($req['industry'])): ?>
                                                        · <span class="badge badge-light" style="font-size:0.7rem;"><?php echo htmlspecialchars($req['industry']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="cv-badge cv-badge-<?php echo htmlspecialchars($req['verification_status']); ?>">
                                            <i class="fas <?php echo $status_info['icon']; ?>"></i>
                                            <?php echo htmlspecialchars($status_info['label']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ((int)$req['doc_count'] > 0): ?>
                                            <div style="font-weight:700;color:var(--text, #1e293b);">
                                                <i class="fas fa-file-shield mr-1 text-primary"></i><?php echo $req['doc_count']; ?> document<?php echo $req['doc_count'] > 1 ? 's' : ''; ?>
                                            </div>
                                            <?php if (!empty($req['latest_doc_date'])): ?>
                                                <small class="text-muted">Updated <?php echo date('M d, Y', strtotime($req['latest_doc_date'])); ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size:0.82rem;"><i class="fas fa-file-circle-xmark mr-1"></i>No evidence uploaded</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-size:0.84rem;color:var(--text, #1e293b);">
                                            <?php echo !empty($req['registration_date']) ? date('M d, Y', strtotime($req['registration_date'])) : '—'; ?>
                                        </div>
                                    </td>
                                    <td style="text-align: right;">
                                        <a href="company_verification_detail.php?id=<?php echo $req['id']; ?>" class="cv-btn-review">
                                            <i class="fas fa-shield-halved"></i> Review Evidence
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="cv-empty">
                    <div class="cv-empty-icon"><i class="fas fa-clipboard-check"></i></div>
                    <h5 style="font-weight:700;">No Verification Requests Found</h5>
                    <p class="text-muted" style="max-width:400px;margin:6px auto 0;">
                        There are currently no company verification requests matching the selected filter.
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>
