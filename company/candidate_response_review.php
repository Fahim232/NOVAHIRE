<?php
/**
 * NovaHire — Company Review of Candidate Selection Response
 *
 * Displays the candidate's response to the selection offer:
 * - Readiness confirmation (Ready to Join / Declined)
 * - Current employment details & notice period
 * - Candidate's approximate joining/availability date
 * - Onboarding documents summary & verification status
 * - Link to review documents or proceed to AI Appointment Letter
 */

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

// Fetch application, job, candidate, and hiring decision
$app_stmt = mysqli_prepare($con, "
    SELECT ja.*, cj.job_title, cj.job_category,
           ui.username, ui.email, ui.phone,
           hd.decision, hd.final_joining_date, hd.candidate_response_status,
           hd.created_at AS decision_date
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

// Fetch candidate selection response
$cea = get_candidate_selection_response($con, $app_id);

// Fetch document requirements and uploaded documents
$req_res = mysqli_query($con, "SELECT * FROM hiring_document_requirements WHERE application_id = $app_id ORDER BY id ASC");
$requirements = [];
if ($req_res) {
    while ($r = mysqli_fetch_assoc($req_res)) {
        $requirements[$r['id']] = $r;
        $requirements[$r['id']]['uploaded_doc'] = null;
    }
}

$doc_res = mysqli_query($con, "SELECT * FROM candidate_documents WHERE application_id = $app_id");
$verified_count = 0;
$uploaded_count = 0;
$rejected_count = 0;

if ($doc_res) {
    while ($d = mysqli_fetch_assoc($doc_res)) {
        $r_id = $d['requirement_id'];
        if (isset($requirements[$r_id])) {
            $requirements[$r_id]['uploaded_doc'] = $d;
        }
        $uploaded_count++;
        if ($d['status'] === 'VERIFIED') $verified_count++;
        if ($d['status'] === 'REJECTED') $rejected_count++;
    }
}

$total_req = count($requirements);

// Check hiring letter status if exists
$hl_res = mysqli_query($con, "SELECT * FROM hiring_letters WHERE application_id = $app_id AND company_id = $company_id LIMIT 1");
$hiring_letter = $hl_res && mysqli_num_rows($hl_res) > 0 ? mysqli_fetch_assoc($hl_res) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Candidate Selection Response | NovaHire</title>
    <?php include '../includes/links.php'; ?>
    <style>
        :root {
            --cr-bg: #f8fafc;
            --cr-card: #ffffff;
            --cr-border: #e2e8f0;
            --cr-text: #0f172a;
            --cr-muted: #64748b;
            --cr-primary: #6366f1;
            --cr-success: #10b981;
            --cr-danger: #ef4444;
            --cr-warning: #f59e0b;
        }
        [data-theme="dark"] {
            --cr-bg: #0f172a;
            --cr-card: #1e293b;
            --cr-border: #334155;
            --cr-text: #f8fafc;
            --cr-muted: #94a3b8;
            --cr-primary: #818cf8;
            --cr-success: #34d399;
            --cr-danger: #f87171;
            --cr-warning: #fbbf24;
        }
        body { background: var(--cr-bg); color: var(--cr-text); font-family: 'Inter', sans-serif; }
        .cr-wrap { max-width: 980px; margin: 30px auto 60px; padding: 0 20px; }
        .cr-back { display: inline-flex; align-items: center; gap: 8px; color: var(--cr-muted); text-decoration: none; font-weight: 600; margin-bottom: 20px; }
        .cr-back:hover { color: var(--cr-primary); }
        .cr-card { background: var(--cr-card); border: 1px solid var(--cr-border); border-radius: 14px; padding: 26px; margin-bottom: 22px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .cr-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid var(--cr-border); }
        .cr-header h2 { margin: 0 0 6px; font-size: 1.35rem; font-weight: 800; color: var(--cr-text); }
        .cr-header p { margin: 0; font-size: 0.88rem; color: var(--cr-muted); }
        .cr-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
        .cr-stat { background: var(--cr-bg); border: 1px solid var(--cr-border); border-radius: 10px; padding: 14px; }
        .cr-stat small { display: block; font-size: 0.72rem; text-transform: uppercase; font-weight: 700; color: var(--cr-muted); letter-spacing: 0.4px; margin-bottom: 4px; }
        .cr-stat strong { font-size: 1rem; color: var(--cr-text); }
        .badge { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-warning { background: #fef3c7; color: #b45309; }
        .badge-info { background: #e0f2fe; color: #075985; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 8px; font-size: 0.88rem; font-weight: 700; text-decoration: none; border: none; cursor: pointer; transition: all 0.2s; }
        .btn-primary { background: var(--cr-primary); color: #fff; }
        .btn-success { background: var(--cr-success); color: #fff; }
        .btn-outline { background: transparent; border: 1px solid var(--cr-border); color: var(--cr-text); }
        .btn:hover { opacity: 0.92; transform: translateY(-1px); }
        table.cr-table { width: 100%; border-collapse: collapse; margin-top: 12px; font-size: 0.88rem; }
        table.cr-table th, table.cr-table td { padding: 10px 14px; text-align: left; border-bottom: 1px solid var(--cr-border); }
        table.cr-table th { background: var(--cr-bg); font-weight: 700; color: var(--cr-muted); font-size: 0.78rem; text-transform: uppercase; }
    </style>
</head>
<body>
<?php include 'company_header.php'; ?>

<div class="cr-wrap">
    <a href="view_applicant_detail.php?id=<?php echo $app_id; ?>" class="cr-back">
        <i class="fas fa-arrow-left"></i> Back to Candidate Details
    </a>

    <!-- Header Card -->
    <div class="cr-card" style="border-top: 4px solid var(--cr-primary);">
        <div class="cr-header">
            <div>
                <span class="badge badge-info" style="margin-bottom: 8px;">
                    <i class="fas fa-briefcase"></i> <?php echo htmlspecialchars($app['job_title']); ?>
                </span>
                <h2>Candidate Appointment Response</h2>
                <p>Candidate: <strong><?php echo htmlspecialchars($app['username']); ?></strong> (<?php echo htmlspecialchars($app['email']); ?> &middot; <?php echo htmlspecialchars($app['phone']); ?>)</p>
            </div>
            <div>
                <?php if ($cea && $cea['ready_to_join'] === 'yes'): ?>
                    <span class="badge badge-success" style="font-size: 0.85rem; padding: 8px 16px;">
                        <i class="fas fa-check-circle"></i> Ready to Join
                    </span>
                <?php elseif ($cea && $cea['ready_to_join'] === 'no'): ?>
                    <span class="badge badge-danger" style="font-size: 0.85rem; padding: 8px 16px;">
                        <i class="fas fa-times-circle"></i> Offer Declined
                    </span>
                <?php else: ?>
                    <span class="badge badge-warning" style="font-size: 0.85rem; padding: 8px 16px;">
                        <i class="fas fa-clock"></i> Response Pending
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$cea): ?>
            <!-- No response yet -->
            <div style="background: #fef3c7; color: #b45309; padding: 18px; border-radius: 10px; border: 1px solid #fde68a;">
                <h4 style="margin: 0 0 6px;"><i class="fas fa-hourglass-half"></i> Awaiting Candidate Response</h4>
                <p style="margin: 0; font-size: 0.88rem;">
                    Candidate was selected on <strong><?php echo !empty($app['decision_date']) ? date('M d, Y', strtotime($app['decision_date'])) : 'recently'; ?></strong>.
                    They have received a notification to confirm whether they are ready to join and submit their availability date and onboarding documents.
                </p>
            </div>
        <?php elseif ($cea['ready_to_join'] === 'no'): ?>
            <!-- Declined -->
            <div style="background: #fef2f2; color: #991b1b; padding: 18px; border-radius: 10px; border: 1px solid #fecaca;">
                <h4 style="margin: 0 0 6px;"><i class="fas fa-circle-xmark"></i> Candidate Declined to Proceed</h4>
                <p style="margin: 0 0 10px; font-size: 0.88rem;">The candidate has indicated that they are unable to accept this appointment offer.</p>
                <div style="background: #fff; padding: 10px 14px; border-radius: 6px; border: 1px solid #fca5a5; font-size: 0.85rem;">
                    <strong>Reason given:</strong> <?php echo htmlspecialchars($cea['decline_reason'] ?: 'No reason provided'); ?>
                </div>
            </div>
        <?php else: ?>
            <!-- Ready to Join Details -->
            <div class="cr-grid">
                <div class="cr-stat">
                    <small>Candidate Decision</small>
                    <strong style="color: var(--cr-success);"><i class="fas fa-check"></i> Ready to Join</strong>
                </div>
                <div class="cr-stat">
                    <small>Approximate Availability Date</small>
                    <strong style="color: var(--cr-primary);"><?php echo date('M d, Y', strtotime($cea['approximate_joining_date'])); ?></strong>
                </div>
                <div class="cr-stat">
                    <small>Currently Working Elsewhere</small>
                    <strong><?php echo ($cea['currently_working'] === 'yes') ? 'Yes' : 'No'; ?></strong>
                </div>
                <?php if ($cea['currently_working'] === 'yes'): ?>
                    <div class="cr-stat">
                        <small>Current Company</small>
                        <strong><?php echo htmlspecialchars($cea['current_company_name'] ?: 'Not specified'); ?></strong>
                    </div>
                    <div class="cr-stat">
                        <small>Expected Leaving Date</small>
                        <strong><?php echo !empty($cea['expected_leaving_date']) ? date('M d, Y', strtotime($cea['expected_leaving_date'])) : 'N/A'; ?></strong>
                    </div>
                    <div class="cr-stat">
                        <small>Notice Period</small>
                        <strong><?php echo intval($cea['notice_period_days']); ?> days</strong>
                    </div>
                <?php else: ?>
                    <div class="cr-stat">
                        <small>Available Immediately</small>
                        <strong><?php echo ($cea['available_immediately'] === 'yes') ? 'Yes — Immediately' : 'No'; ?></strong>
                    </div>
                <?php endif; ?>
                <div class="cr-stat">
                    <small>Response Submitted At</small>
                    <strong><?php echo date('M d, Y, h:i A', strtotime($cea['submitted_at'])); ?></strong>
                </div>
            </div>

            <?php if (!empty($cea['candidate_notes'])): ?>
                <div style="margin-top: 16px; padding: 14px; background: var(--cr-bg); border-radius: 10px; border: 1px solid var(--cr-border);">
                    <small style="display: block; font-size: 0.72rem; text-transform: uppercase; font-weight: 700; color: var(--cr-muted); margin-bottom: 4px;">Candidate Notes</small>
                    <p style="margin: 0; font-size: 0.88rem; color: var(--cr-text);"><?php echo nl2br(htmlspecialchars($cea['candidate_notes'])); ?></p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Onboarding Documents Section -->
    <?php if ($cea && $cea['ready_to_join'] === 'yes'): ?>
        <div class="cr-card">
            <div class="cr-header">
                <div>
                    <h2>Required Onboarding Documents</h2>
                    <p>Review candidate's uploaded credentials before issuing the official appointment letter.</p>
                </div>
                <div style="display: flex; gap: 8px;">
                    <span class="badge badge-info"><?php echo $uploaded_count . ' / ' . $total_req; ?> Uploaded</span>
                    <span class="badge badge-success"><?php echo $verified_count; ?> Verified</span>
                    <?php if ($rejected_count > 0): ?>
                        <span class="badge badge-danger"><?php echo $rejected_count; ?> Rejected</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($requirements)): ?>
                <p style="color: var(--cr-muted); font-size: 0.88rem;">No document requirements configured yet.</p>
            <?php else: ?>
                <table class="cr-table">
                    <thead>
                        <tr>
                            <th>Requirement</th>
                            <th>Status</th>
                            <th>Uploaded File</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requirements as $req): 
                            $doc = $req['uploaded_doc'];
                            $st = $doc ? $doc['status'] : ($req['status'] ?? 'PENDING');
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($req['title']); ?></strong>
                                    <div style="font-size: 0.75rem; color: var(--cr-muted);"><?php echo htmlspecialchars($req['description']); ?></div>
                                </td>
                                <td>
                                    <?php if ($st === 'VERIFIED'): ?>
                                        <span class="badge badge-success"><i class="fas fa-check"></i> Verified</span>
                                    <?php elseif ($st === 'REJECTED'): ?>
                                        <span class="badge badge-danger"><i class="fas fa-times"></i> Rejected</span>
                                    <?php elseif ($st === 'UPLOADED'): ?>
                                        <span class="badge badge-warning"><i class="fas fa-clock"></i> Pending Review</span>
                                    <?php else: ?>
                                        <span class="badge badge-info"><i class="fas fa-hourglass"></i> Not Uploaded</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($doc): ?>
                                        <a href="../api/serve_document.php?doc_id=<?php echo $doc['id']; ?>" target="_blank" style="color: var(--cr-primary); text-decoration: none; font-weight: 600;">
                                            <i class="fas fa-file-pdf"></i> <?php echo htmlspecialchars($doc['original_filename']); ?>
                                        </a>
                                        <div style="font-size: 0.72rem; color: var(--cr-muted);"><?php echo round($doc['file_size'] / 1024, 1); ?> KB</div>
                                    <?php else: ?>
                                        <span style="color: var(--cr-muted);">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo $doc ? date('M d, Y', strtotime($doc['uploaded_at'])) : '&mdash;'; ?>
                                </td>
                                <td>
                                    <a href="manage_hiring_documents.php?application_id=<?php echo $app_id; ?>" class="btn btn-outline" style="padding: 5px 12px; font-size: 0.78rem;">
                                        Review
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <div style="margin-top: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                <a href="manage_hiring_documents.php?application_id=<?php echo $app_id; ?>" class="btn btn-outline">
                    <i class="fas fa-folder-open"></i> Full Document Manager
                </a>

                <a href="hiring_letter.php?application_id=<?php echo $app_id; ?>" class="btn btn-success" style="padding: 12px 24px; font-size: 0.95rem;">
                    <i class="fas fa-wand-magic-sparkles"></i> 
                    <?php echo ($hiring_letter && $hiring_letter['status'] === 'SENT') ? 'View Appointment Letter' : 'Generate AI Appointment Letter'; ?>
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
