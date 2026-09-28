<?php
/**
 * NovaHire - View AI Generated CV Snapshot
 * 
 * Accessible by:
 * - Candidate who owns the CV
 * - Company reviewing the applicant
 * - System Administrator
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../admin/dbcon.php';

$is_seeker  = isset($_SESSION['id']);
$is_company = isset($_SESSION['company_id']);
$is_admin   = isset($_SESSION['admin_username']) || isset($_SESSION['admin_id']);

if (!$is_seeker && !$is_company && !$is_admin) {
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}

$cv_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($cv_id <= 0) {
    die('<div style="text-align:center;padding:50px;font-family:sans-serif;"><h2>Error: Invalid CV ID</h2><p><a href="javascript:history.back()">Go Back</a></p></div>');
}

$stmt = mysqli_prepare($con, "SELECT * FROM ai_generated_cvs WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $cv_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$cv = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$cv) {
    die('<div style="text-align:center;padding:50px;font-family:sans-serif;"><h2>AI-Generated CV Not Found</h2><p><a href="javascript:history.back()">Go Back</a></p></div>');
}

// Authorization check: if logged in as seeker, must be their own CV
if ($is_seeker && !$is_company && !$is_admin) {
    if (intval($cv['user_id']) !== intval($_SESSION['id'])) {
        die('<div style="text-align:center;padding:50px;font-family:sans-serif;"><h2>Access Denied</h2><p>You do not have permission to view this CV.</p></div>');
    }
}

// Decode JSON fields
$skills     = json_decode($cv['skills_json'] ?? '[]', true) ?: [];
$experience = json_decode($cv['experience_json'] ?? '[]', true) ?: [];
$education  = json_decode($cv['education_json'] ?? '[]', true) ?: [];
$projects   = json_decode($cv['projects_json'] ?? '[]', true) ?: [];

$full_name  = htmlspecialchars($cv['full_name'] ?: 'Job Candidate');
$headline   = htmlspecialchars($cv['headline'] ?: '');
$email      = htmlspecialchars($cv['email'] ?: '');
$phone      = htmlspecialchars($cv['phone'] ?: '');
$location   = htmlspecialchars($cv['location'] ?: '');
$summary    = htmlspecialchars($cv['summary'] ?: '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $full_name; ?> - AI Customized CV | NovaHire</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Playfair+Display:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --sidebar-bg: #0f172a;
            --body-bg: #f1f5f9;
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --accent: #38bdf8;
        }

        * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        body {
            background-color: var(--body-bg);
            font-family: 'Plus Jakarta Sans', sans-serif;
            margin: 0;
            padding: 30px 15px;
            color: var(--text-dark);
            min-height: 100vh;
        }

        .action-bar {
            max-width: 210mm;
            margin: 0 auto 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: white;
            padding: 14px 22px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .action-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.88rem;
            font-weight: 700;
            color: #4338ca;
            background: #e0e7ff;
            padding: 6px 14px;
            border-radius: 20px;
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 18px;
            border-radius: 8px;
            font-size: 0.88rem;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none !important;
            transition: all .2s;
            border: none;
        }

        .btn-print {
            background: var(--primary);
            color: white;
        }
        .btn-print:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-back {
            background: #e2e8f0;
            color: #334155;
            margin-right: 10px;
        }
        .btn-back:hover {
            background: #cbd5e1;
        }

        .cv-container {
            max-width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            background: white;
            box-shadow: 0 15px 45px rgba(15, 23, 42, 0.12);
            border-radius: 12px;
            overflow: hidden;
            display: grid;
            grid-template-columns: 34% 66%;
        }

        /* Sidebar Column */
        .cv-sidebar {
            background: var(--sidebar-bg);
            color: white;
            padding: 45px 30px;
            display: flex;
            flex-direction: column;
            gap: 32px;
        }

        .sidebar-header h2 {
            font-size: 1.65rem;
            font-weight: 800;
            margin: 0 0 6px;
            line-height: 1.2;
            color: white;
        }

        .sidebar-headline {
            font-size: 0.95rem;
            color: var(--accent);
            font-weight: 600;
            margin: 0;
            line-height: 1.4;
        }

        .sidebar-block {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .sidebar-title {
            text-transform: uppercase;
            font-size: 0.76rem;
            letter-spacing: 1.5px;
            font-weight: 800;
            color: #94a3b8;
            margin: 0;
            padding-bottom: 8px;
            border-bottom: 1px solid #334155;
        }

        .contact-entry {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: 0.88rem;
            color: #cbd5e1;
            word-break: break-word;
        }

        .contact-entry i {
            color: var(--accent);
            font-size: 0.95rem;
            margin-top: 3px;
            width: 16px;
            text-align: center;
        }

        .skills-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .skill-chip {
            background: rgba(255, 255, 255, 0.1);
            color: #f1f5f9;
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 0.82rem;
            font-weight: 600;
            border: 1px solid rgba(255,255,255,0.12);
        }

        /* Main Content Column */
        .cv-main {
            padding: 45px 40px;
            display: flex;
            flex-direction: column;
            gap: 32px;
        }

        .section-title {
            font-size: 1.12rem;
            font-weight: 800;
            color: #0f172a;
            margin: 0 0 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--border-color);
        }

        .section-title i {
            color: var(--primary);
            font-size: 1.05rem;
        }

        .summary-text {
            font-size: 0.92rem;
            line-height: 1.65;
            color: #475569;
            margin: 0;
        }

        .timeline-item {
            position: relative;
            padding-left: 22px;
            border-left: 2px solid var(--border-color);
            margin-bottom: 24px;
        }
        .timeline-item:last-child {
            margin-bottom: 0;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -6px;
            top: 4px;
            width: 10px;
            height: 10px;
            background: var(--primary);
            border-radius: 50%;
        }

        .timeline-title {
            font-size: 1rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 3px;
        }

        .timeline-subtitle {
            font-size: 0.85rem;
            font-weight: 600;
            color: #64748b;
            margin: 0 0 8px;
        }

        .timeline-desc {
            font-size: 0.88rem;
            line-height: 1.55;
            color: #334155;
            margin: 0;
        }

        @media print {
            body {
                background: white;
                padding: 0;
            }
            .action-bar {
                display: none !important;
            }
            .cv-container {
                box-shadow: none;
                border-radius: 0;
                width: 100%;
                max-width: 100%;
            }
        }

        @media (max-width: 768px) {
            .cv-container {
                grid-template-columns: 1fr;
            }
            .action-bar {
                flex-direction: column;
                gap: 12px;
            }
        }
    </style>
</head>
<body>

<div class="action-bar">
    <div class="action-badge">
        <i class="fas fa-robot"></i> AI-Customized CV Snapshot
    </div>
    <div>
        <button onclick="window.history.back()" class="action-btn btn-back">
            <i class="fas fa-arrow-left"></i> Back
        </button>
        <button onclick="window.print()" class="action-btn btn-print">
            <i class="fas fa-print"></i> Print / Save as PDF
        </button>
    </div>
</div>

<div class="cv-container">
    <!-- Sidebar -->
    <aside class="cv-sidebar">
        <div class="sidebar-header">
            <h2><?php echo $full_name; ?></h2>
            <?php if (!empty($headline)): ?>
                <p class="sidebar-headline"><?php echo $headline; ?></p>
            <?php endif; ?>
        </div>

        <div class="sidebar-block">
            <h3 class="sidebar-title">Contact</h3>
            <?php if (!empty($email)): ?>
                <div class="contact-entry">
                    <i class="fas fa-envelope"></i>
                    <span><?php echo $email; ?></span>
                </div>
            <?php endif; ?>
            <?php if (!empty($phone)): ?>
                <div class="contact-entry">
                    <i class="fas fa-phone"></i>
                    <span><?php echo $phone; ?></span>
                </div>
            <?php endif; ?>
            <?php if (!empty($location)): ?>
                <div class="contact-entry">
                    <i class="fas fa-map-marker-alt"></i>
                    <span><?php echo $location; ?></span>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($skills)): ?>
            <div class="sidebar-block">
                <h3 class="sidebar-title">Key Skills</h3>
                <div class="skills-wrap">
                    <?php foreach ($skills as $skill): ?>
                        <span class="skill-chip"><?php echo htmlspecialchars(is_array($skill) ? ($skill['name'] ?? '') : $skill); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </aside>

    <!-- Main Content -->
    <main class="cv-main">
        <?php if (!empty($summary)): ?>
            <section>
                <h3 class="section-title"><i class="fas fa-user-circle"></i> Professional Summary</h3>
                <p class="summary-text"><?php echo nl2br($summary); ?></p>
            </section>
        <?php endif; ?>

        <?php if (!empty($experience)): ?>
            <section>
                <h3 class="section-title"><i class="fas fa-briefcase"></i> Work Experience</h3>
                <?php foreach ($experience as $exp): ?>
                    <div class="timeline-item">
                        <h4 class="timeline-title"><?php echo htmlspecialchars($exp['title'] ?? $exp['position'] ?? 'Role'); ?></h4>
                        <div class="timeline-subtitle">
                            <?php echo htmlspecialchars($exp['company'] ?? ''); ?>
                            <?php if (!empty($exp['duration']) || !empty($exp['period'])): ?>
                                • <?php echo htmlspecialchars($exp['duration'] ?? $exp['period'] ?? ''); ?>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($exp['description'])): ?>
                            <p class="timeline-desc"><?php echo nl2br(htmlspecialchars($exp['description'])); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <?php if (!empty($education)): ?>
            <section>
                <h3 class="section-title"><i class="fas fa-graduation-cap"></i> Education</h3>
                <?php foreach ($education as $edu): ?>
                    <div class="timeline-item">
                        <h4 class="timeline-title"><?php echo htmlspecialchars($edu['degree'] ?? 'Degree'); ?></h4>
                        <div class="timeline-subtitle">
                            <?php echo htmlspecialchars($edu['institution'] ?? $edu['school'] ?? ''); ?>
                            <?php if (!empty($edu['year']) || !empty($edu['duration'])): ?>
                                • <?php echo htmlspecialchars($edu['year'] ?? $edu['duration'] ?? ''); ?>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($edu['details'])): ?>
                            <p class="timeline-desc"><?php echo nl2br(htmlspecialchars($edu['details'])); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <?php if (!empty($projects)): ?>
            <section>
                <h3 class="section-title"><i class="fas fa-diagram-project"></i> Key Projects</h3>
                <?php foreach ($projects as $proj): ?>
                    <div class="timeline-item">
                        <h4 class="timeline-title"><?php echo htmlspecialchars($proj['name'] ?? $proj['title'] ?? 'Project'); ?></h4>
                        <?php if (!empty($proj['tools']) || !empty($proj['technologies'])): ?>
                            <div class="timeline-subtitle">
                                <?php echo htmlspecialchars($proj['tools'] ?? $proj['technologies'] ?? ''); ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($proj['description'])): ?>
                            <p class="timeline-desc"><?php echo nl2br(htmlspecialchars($proj['description'])); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    </main>
</div>

</body>
</html>
