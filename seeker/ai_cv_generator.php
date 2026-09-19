<?php
/**
 * NovaHire AI - Interactive CV Generator & Live Canvas Builder
 * 
 * Features:
 * - Hybrid AI generation (LLM + smart offline fallback) tailored to target job
 * - Interactive Live A4 Preview Canvas with real-time inline editing (`contenteditable`)
 * - Dynamic 3-Template Switcher (Modern Clean, Executive Two-Column, Minimalist Tech)
 * - Section Enhancers ("✨ Rewrite Summary", "✨ Polish Bullets", "✨ Elevate Headline")
 * - Dynamic Skill Pill management with inline addition and deletion
 * - Real-time persistence to database (`ai_generated_cvs`)
 * - High-definition PDF Export & A4 Print CSS
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_seeker_login();

require_once __DIR__ . '/../admin/dbcon.php';
require_once __DIR__ . '/../ai/config.php';
require_once __DIR__ . '/../ai/engine.php';
require_once __DIR__ . '/../ai/cv_generator.php';

$user_id = intval($_SESSION['id']);

// Check premium access
require_once __DIR__ . '/../includes/premium.php';
$access = nh_check_access($con, $user_id, 'resume_builder');
if (!$access['allowed']) {
    nh_render_pro_gate('ai_cv_generator');
    exit;
}

// Fetch user profile data
$user_q = mysqli_query($con, "SELECT * FROM user_info WHERE id = '$user_id'");
$user = mysqli_fetch_assoc($user_q);
if (!$user) {
    $user = [];
}

// Ensure table exists
ai_ensure_cv_table($con);

// Fetch active company jobs for the target job selector
$jobs = [];
$jq = mysqli_query($con, "SELECT cj.id, cj.job_title, cj.job_category, cj.skills_required, c.company_name, c.logo 
                          FROM company_jobs cj 
                          JOIN companies c ON cj.company_id = c.id 
                          WHERE cj.status = 'active' AND (cj.deadline IS NULL OR cj.deadline >= CURDATE()) 
                          ORDER BY cj.posted_date DESC");
if ($jq) {
    while ($j = mysqli_fetch_assoc($jq)) {
        $jobs[] = $j;
    }
}

// Check if loading a specific saved CV snapshot
$load_id = isset($_GET['load_id']) ? intval($_GET['load_id']) : 0;
$initial_cv = null;
$selected_template = 'modern';
$selected_job_id = null;

if ($load_id > 0) {
    $load_q = mysqli_query($con, "SELECT * FROM ai_generated_cvs WHERE id = '$load_id' AND user_id = '$user_id' LIMIT 1");
    if ($load_q && $saved_row = mysqli_fetch_assoc($load_q)) {
        $selected_template = $saved_row['template_name'];
        $selected_job_id   = $saved_row['job_id'];
        $initial_cv = [
            'mode'        => 'saved',
            'provider'    => 'Saved Snapshot',
            'full_name'   => $saved_row['full_name'],
            'headline'    => $saved_row['headline'],
            'email'       => $saved_row['email'],
            'phone'       => $saved_row['phone'],
            'location'    => $saved_row['location'],
            'summary'     => $saved_row['summary'],
            'skills'      => json_decode($saved_row['skills_json'] ?? '[]', true) ?: [],
            'experience'  => json_decode($saved_row['experience_json'] ?? '[]', true) ?: [],
            'education'   => json_decode($saved_row['education_json'] ?? '[]', true) ?: [],
            'projects'    => json_decode($saved_row['projects_json'] ?? '[]', true) ?: [],
            'cv_id'       => $saved_row['id'],
        ];
    }
}

// If no saved snapshot, generate initial baseline from profile
if (!$initial_cv) {
    $initial_cv = ai_generate_cv_content($user, null);
    $initial_cv['cv_id'] = 0;
}

// Prepare JSON for instant client-side boot
$initial_cv_json = json_encode($initial_cv, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Extra Google Fonts for CV Templates -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fira+Code:wght@400;500;600&family=Inter:wght@300;400;500;600;700;800&family=Playfair+Display:ital,wght@0,600;0,700;0,800;1,600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<!-- html2pdf for direct client-side PDF downloads -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<style>
    :root {
        --cv-primary: #4f46e5;
        --cv-primary-dark: #3730a3;
        --cv-primary-light: #e0e7ff;
        --cv-secondary: #0ea5e9;
        --cv-accent: #f43f5e;
        --cv-bg: #f1f5f9;
        --cv-panel-bg: #ffffff;
        --cv-text-main: #0f172a;
        --cv-text-muted: #64748b;
        --cv-border: #e2e8f0;
        --cv-shadow: 0 10px 30px -5px rgba(15, 23, 42, 0.08), 0 4px 12px -2px rgba(15, 23, 42, 0.04);
        --cv-paper-shadow: 0 25px 60px -12px rgba(15, 23, 42, 0.18), 0 0 0 1px rgba(15, 23, 42, 0.05);
    }

    :root[data-theme="dark"],
    [data-theme="dark"],
    body.dark-theme,
    body[data-theme="dark"] {
        --cv-bg: #0f172a;
        --cv-panel-bg: #1e293b;
        --cv-text-main: #f1f5f9;
        --cv-text-muted: #94a3b8;
        --cv-border: #334155;
        --cv-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.4), 0 4px 12px -2px rgba(0, 0, 0, 0.3);
    }

    /* Bootstrap 4 gap helper */
    .gap-1 { gap: 0.25rem !important; }
    .gap-2 { gap: 0.5rem !important; }
    .gap-3 { gap: 1rem !important; }

    [data-theme="dark"] .tpl-card,
    body.dark-theme .tpl-card,
    body[data-theme="dark"] .tpl-card {
        background: #0f172a !important;
        border-color: #334155 !important;
    }
    [data-theme="dark"] .tpl-card.active,
    body.dark-theme .tpl-card.active,
    body[data-theme="dark"] .tpl-card.active {
        background: #312e81 !important;
        border-color: #6366f1 !important;
    }
    [data-theme="dark"] .btn-quick-add,
    body.dark-theme .btn-quick-add,
    body[data-theme="dark"] .btn-quick-add {
        background: #0f172a !important;
        border-color: #334155 !important;
        color: #94a3b8 !important;
    }
    [data-theme="dark"] .btn-quick-add:hover,
    body.dark-theme .btn-quick-add:hover,
    body[data-theme="dark"] .btn-quick-add:hover {
        background: #312e81 !important;
        border-color: #6366f1 !important;
        color: #c7d2fe !important;
    }
    [data-theme="dark"] .btn-secondary-action,
    body.dark-theme .btn-secondary-action,
    body[data-theme="dark"] .btn-secondary-action {
        background: #0f172a !important;
        border-color: #334155 !important;
        color: #cbd5e1 !important;
    }
    [data-theme="dark"] .btn-secondary-action:hover,
    body.dark-theme .btn-secondary-action:hover,
    body[data-theme="dark"] .btn-secondary-action:hover {
        background: #334155 !important;
        color: #ffffff !important;
    }
    [data-theme="dark"] .cv-workbench-toolbar,
    body.dark-theme .cv-workbench-toolbar,
    body[data-theme="dark"] .cv-workbench-toolbar {
        background: #1e293b !important;
        border-color: #334155 !important;
        color: #f1f5f9 !important;
    }
    [data-theme="dark"] .btn-zoom,
    body.dark-theme .btn-zoom,
    body[data-theme="dark"] .btn-zoom {
        background: #0f172a !important;
        border-color: #334155 !important;
        color: #cbd5e1 !important;
    }
    [data-theme="dark"] .custom-select,
    body.dark-theme .custom-select,
    body[data-theme="dark"] .custom-select {
        background-color: #0f172a !important;
        border-color: #334155 !important;
        color: #f1f5f9 !important;
    }
    [data-theme="dark"] .custom-select option,
    body.dark-theme .custom-select option,
    body[data-theme="dark"] .custom-select option {
        background-color: #0f172a !important;
        color: #f1f5f9 !important;
    }
    [data-theme="dark"] .modal-content,
    body.dark-theme .modal-content,
    body[data-theme="dark"] .modal-content {
        background: #1e293b !important;
        border: 1px solid #334155 !important;
        color: #f1f5f9 !important;
    }
    [data-theme="dark"] .list-group-item,
    body.dark-theme .list-group-item,
    body[data-theme="dark"] .list-group-item {
        background: #0f172a !important;
        border-color: #334155 !important;
        color: #f1f5f9 !important;
    }

    body {
        background: var(--cv-bg);
        font-family: 'Plus Jakarta Sans', sans-serif;
        color: var(--cv-text-main);
        min-height: 100vh;
    }

    /* ── Workspace App Bar ── */
    .cv-app-header {
        background: linear-gradient(135deg, #1e1b4b 0%, #312e81 50%, #4338ca 100%);
        color: white;
        padding: 32px 0 24px;
        margin-top: -80px;
        position: relative;
        box-shadow: 0 10px 25px -5px rgba(30, 27, 75, 0.3);
    }
    .cv-app-badge {
        background: rgba(255, 255, 255, 0.16);
        border: 1px solid rgba(255, 255, 255, 0.25);
        backdrop-filter: blur(8px);
        padding: 5px 14px;
        border-radius: 999px;
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.5px;
        display: inline-flex;
        align-items: center;
        gap: 7px;
    }
    .cv-app-title {
        font-size: 1.85rem;
        font-weight: 800;
        margin: 10px 0 4px;
        letter-spacing: -0.02em;
    }
    .cv-app-subtitle {
        color: rgba(255, 255, 255, 0.8);
        font-size: 0.92rem;
        margin: 0;
    }

    /* ── Main 2-Panel Layout ── */
    .cv-workspace {
        padding: 24px 0 60px;
    }
    .cv-sidebar-tools {
        position: sticky;
        top: 90px;
        max-height: calc(100vh - 110px);
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: #cbd5e1 transparent;
        padding-right: 4px;
    }
    .cv-sidebar-tools::-webkit-scrollbar { width: 6px; }
    .cv-sidebar-tools::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

    /* ── Tool Cards & Sections ── */
    .cv-tool-card {
        background: var(--cv-panel-bg);
        border: 1px solid var(--cv-border);
        border-radius: 18px;
        padding: 20px;
        margin-bottom: 18px;
        box-shadow: var(--cv-shadow);
        transition: all 0.25s ease;
    }
    .cv-tool-card:hover {
        border-color: #cbd5e1;
        box-shadow: 0 14px 35px -8px rgba(15, 23, 42, 0.1);
    }
    .cv-tool-heading {
        font-size: 0.95rem;
        font-weight: 800;
        color: var(--cv-text-main);
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 14px;
    }
    .cv-tool-heading i {
        color: var(--cv-primary);
        font-size: 1rem;
    }

    /* Template Switcher Cards */
    .template-picker-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 10px;
    }
    .tpl-card {
        border: 2px solid var(--cv-border);
        border-radius: 12px;
        padding: 10px 8px;
        text-align: center;
        cursor: pointer;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        background: #fafafa;
        position: relative;
    }
    .tpl-card:hover {
        border-color: #818cf8;
        transform: translateY(-2px);
        background: #ffffff;
    }
    .tpl-card.active {
        border-color: var(--cv-primary);
        background: #eef2ff;
        box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.2);
    }
    .tpl-card-icon {
        font-size: 1.25rem;
        margin-bottom: 6px;
        color: #64748b;
    }
    .tpl-card.active .tpl-card-icon { color: var(--cv-primary); }
    .tpl-card-name {
        font-size: 0.76rem;
        font-weight: 700;
        color: var(--cv-text-main);
        line-height: 1.2;
    }
    .tpl-card-tag {
        font-size: 0.62rem;
        color: #64748b;
        margin-top: 3px;
    }

    /* Generate Button */
    .btn-generate-ai {
        background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
        color: white;
        border: none;
        border-radius: 12px;
        padding: 14px 20px;
        font-weight: 700;
        font-size: 0.95rem;
        width: 100%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        cursor: pointer;
        box-shadow: 0 10px 25px -5px rgba(79, 70, 229, 0.45);
        transition: all 0.25s ease;
    }
    .btn-generate-ai:hover {
        transform: translateY(-2px);
        box-shadow: 0 14px 30px -5px rgba(79, 70, 229, 0.6);
        color: white;
    }
    .btn-generate-ai:disabled {
        opacity: 0.7;
        cursor: not-allowed;
        transform: none;
    }

    /* Quick Custom Section Buttons */
    .quick-add-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    .btn-quick-add {
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
        border-radius: 10px;
        padding: 9px 10px;
        font-size: 0.78rem;
        font-weight: 600;
        color: #475569;
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
    }
    .btn-quick-add:hover {
        background: #eef2ff;
        border-color: var(--cv-primary);
        color: var(--cv-primary);
    }

    /* Action Buttons */
    .cv-action-stack {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .btn-save-cv {
        background: #10b981;
        color: white;
        border: none;
        border-radius: 12px;
        padding: 12px;
        font-weight: 700;
        font-size: 0.9rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        cursor: pointer;
        transition: all 0.2s;
        box-shadow: 0 6px 18px -4px rgba(16, 185, 129, 0.4);
    }
    .btn-save-cv:hover {
        background: #059669;
        transform: translateY(-2px);
        color: white;
    }
    .btn-export-pdf {
        background: #2563eb;
        color: white;
        border: none;
        border-radius: 12px;
        padding: 12px;
        font-weight: 700;
        font-size: 0.9rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        cursor: pointer;
        transition: all 0.2s;
        box-shadow: 0 6px 18px -4px rgba(37, 99, 235, 0.4);
    }
    .btn-export-pdf:hover {
        background: #1d4ed8;
        transform: translateY(-2px);
        color: white;
    }
    .btn-secondary-action {
        background: #f8fafc;
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        padding: 9px;
        font-size: 0.82rem;
        font-weight: 600;
        color: #475569;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        cursor: pointer;
        transition: all 0.2s;
    }
    .btn-secondary-action:hover {
        background: #e2e8f0;
        color: #1e293b;
    }

    /* ── Right Canvas Viewport ── */
    .cv-preview-workbench {
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    .cv-workbench-toolbar {
        width: 100%;
        max-width: 210mm;
        background: white;
        border: 1px solid var(--cv-border);
        border-radius: 14px;
        padding: 10px 18px;
        margin-bottom: 18px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        box-shadow: var(--cv-shadow);
    }
    .canvas-scale-tools {
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .btn-zoom {
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 4px 10px;
        font-size: 0.78rem;
        font-weight: 600;
        color: #475569;
        cursor: pointer;
    }
    .btn-zoom:hover { background: #e2e8f0; }

    /* ── Physical A4 Paper Canvas ── */
    .cv-a4-sheet-container {
        width: 100%;
        display: flex;
        justify-content: center;
        overflow-x: auto;
        padding-bottom: 30px;
    }
    .cv-a4-sheet {
        width: 210mm;
        min-height: 297mm;
        background: #ffffff;
        box-shadow: var(--cv-paper-shadow);
        border-radius: 6px;
        position: relative;
        box-sizing: border-box;
        transform-origin: top center;
        transition: transform 0.25s ease;
    }

    /* ── Live In-place Content Editable Styling ── */
    [contenteditable="true"] {
        outline: none;
        transition: background 0.2s, box-shadow 0.2s;
        border-radius: 4px;
        padding: 1px 3px;
        cursor: text;
    }
    [contenteditable="true"]:hover {
        background: rgba(79, 70, 229, 0.05);
        box-shadow: 0 0 0 1px rgba(79, 70, 229, 0.2);
    }
    [contenteditable="true"]:focus {
        background: #ffffff;
        box-shadow: 0 0 0 2px var(--cv-primary);
    }

    /* Interactive Hover Controls on CV Items */
    .cv-interactive-item {
        position: relative;
        transition: all 0.2s;
        border-radius: 6px;
        padding: 4px;
        margin: -4px;
    }
    .cv-interactive-item:hover {
        background: rgba(241, 245, 249, 0.6);
    }
    .cv-item-actions {
        position: absolute;
        top: 2px;
        right: 2px;
        display: none;
        align-items: center;
        gap: 4px;
        background: white;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 2px 6px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        z-index: 10;
    }
    .cv-interactive-item:hover .cv-item-actions {
        display: inline-flex;
    }
    .btn-cv-micro {
        border: none;
        background: transparent;
        font-size: 0.72rem;
        color: #64748b;
        padding: 2px 5px;
        border-radius: 4px;
        cursor: pointer;
        transition: all 0.15s;
    }
    .btn-cv-micro:hover {
        background: #eef2ff;
        color: var(--cv-primary);
    }
    .btn-cv-micro.btn-micro-danger:hover {
        background: #fee2e2;
        color: #dc2626;
    }

    /* Skill Pills Interactive */
    .skill-pill-interactive {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        background: #eef2ff;
        color: #3730a3;
        border: 1px solid #c7d2fe;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        margin: 3px 4px 3px 0;
        transition: all 0.2s;
    }
    .skill-pill-interactive:hover {
        background: #e0e7ff;
    }
    .skill-pill-del {
        cursor: pointer;
        color: #818cf8;
        font-size: 0.85rem;
        line-height: 1;
        padding: 0 2px;
        border-radius: 50%;
    }
    .skill-pill-del:hover {
        color: #dc2626;
    }
    .btn-add-skill-inline {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        background: #f8fafc;
        border: 1px dashed #94a3b8;
        color: #64748b;
        padding: 3px 9px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
        cursor: pointer;
        margin: 3px 4px 3px 0;
        transition: all 0.2s;
    }
    .btn-add-skill-inline:hover {
        border-color: var(--cv-primary);
        color: var(--cv-primary);
        background: #eef2ff;
    }

    /* Section Enhance Bar */
    .section-header-wrap {
        display: flex;
        align-items: center;
        justify-content: space-between;
        position: relative;
    }
    .btn-section-enhance {
        font-size: 0.72rem;
        font-weight: 700;
        color: #6366f1;
        background: #eef2ff;
        border: 1px solid #c7d2fe;
        border-radius: 6px;
        padding: 3px 8px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        transition: all 0.2s;
        opacity: 0.85;
    }
    .btn-section-enhance:hover {
        opacity: 1;
        background: #4f46e5;
        color: #ffffff;
        border-color: #4f46e5;
    }

    /* ========================================================
       TEMPLATE 1: MODERN CLEAN (tpl-modern)
       ======================================================== */
    .tpl-modern {
        padding: 40px 42px;
        color: #1e293b;
        font-family: 'Plus Jakarta Sans', sans-serif;
    }
    .tpl-modern .cv-header {
        border-bottom: 2px solid #e2e8f0;
        padding-bottom: 24px;
        margin-bottom: 24px;
    }
    .tpl-modern .cv-name {
        font-size: 2.3rem;
        font-weight: 800;
        color: #0f172a;
        letter-spacing: -0.03em;
        margin: 0;
        line-height: 1.15;
    }
    .tpl-modern .cv-title {
        font-size: 1.08rem;
        font-weight: 700;
        color: #4f46e5;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin: 6px 0 14px;
    }
    .tpl-modern .cv-contacts {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        font-size: 0.85rem;
        color: #475569;
    }
    .tpl-modern .cv-contacts span {
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .tpl-modern .cv-contacts i { color: #6366f1; }

    .tpl-modern .cv-body-grid {
        display: grid;
        grid-template-columns: 65% 35%;
        gap: 32px;
    }
    .tpl-modern .sec-title {
        font-size: 1rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        color: #0f172a;
        padding-bottom: 6px;
        border-bottom: 2px solid #4f46e5;
        margin-bottom: 14px;
    }
    .tpl-modern .cv-exp-role {
        font-size: 0.98rem;
        font-weight: 700;
        color: #0f172a;
    }
    .tpl-modern .cv-exp-company {
        font-size: 0.86rem;
        font-weight: 600;
        color: #4f46e5;
    }
    .tpl-modern .cv-exp-meta {
        font-size: 0.78rem;
        color: #64748b;
        font-weight: 500;
    }
    .tpl-modern .cv-bullets {
        margin: 8px 0 16px 18px;
        padding: 0;
        font-size: 0.85rem;
        line-height: 1.65;
        color: #334155;
    }
    .tpl-modern .cv-bullets li {
        margin-bottom: 6px;
    }
    .tpl-modern .cv-summary-text {
        font-size: 0.88rem;
        line-height: 1.7;
        color: #334155;
        margin-bottom: 20px;
    }

    /* ========================================================
       TEMPLATE 2: EXECUTIVE TWO-COLUMN (tpl-executive)
       ======================================================== */
    .tpl-executive {
        display: grid;
        grid-template-columns: 32% 68%;
        min-height: 297mm;
        font-family: 'Inter', sans-serif;
        background: #ffffff;
    }
    .tpl-executive .exec-sidebar {
        background: #0f172a;
        color: #f8fafc;
        padding: 40px 24px;
    }
    .tpl-executive .exec-avatar-badge {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        background: #334155;
        border: 3px solid #64748b;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.8rem;
        color: #f8fafc;
        margin-bottom: 24px;
    }
    .tpl-executive .exec-side-sec {
        margin-bottom: 28px;
    }
    .tpl-executive .exec-side-title {
        font-size: 0.82rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 1.5px;
        color: #94a3b8;
        border-bottom: 1px solid #334155;
        padding-bottom: 6px;
        margin-bottom: 12px;
    }
    .tpl-executive .exec-contact-item {
        font-size: 0.8rem;
        color: #cbd5e1;
        margin-bottom: 10px;
        display: flex;
        align-items: flex-start;
        gap: 8px;
        line-height: 1.4;
    }
    .tpl-executive .exec-contact-item i {
        color: #38bdf8;
        font-size: 0.85rem;
        margin-top: 2px;
    }
    .tpl-executive .exec-sidebar .skill-pill-interactive {
        background: #1e293b;
        color: #38bdf8;
        border-color: #334155;
    }
    .tpl-executive .exec-sidebar .skill-pill-interactive:hover {
        background: #334155;
    }
    .tpl-executive .exec-sidebar .btn-add-skill-inline {
        background: #1e293b;
        color: #94a3b8;
        border-color: #334155;
    }
    .tpl-executive .exec-sidebar .btn-add-skill-inline:hover {
        color: #38bdf8;
        border-color: #38bdf8;
    }
    .tpl-executive .exec-main {
        padding: 40px 36px;
        color: #1e293b;
    }
    .tpl-executive .exec-name {
        font-family: 'Playfair Display', serif;
        font-size: 2.4rem;
        font-weight: 700;
        color: #0f172a;
        margin: 0;
        line-height: 1.1;
    }
    .tpl-executive .exec-title {
        font-size: 0.95rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 2px;
        color: #0284c7;
        margin: 8px 0 20px;
    }
    .tpl-executive .exec-summary-box {
        background: #f8fafc;
        border-left: 4px solid #0284c7;
        padding: 14px 16px;
        border-radius: 0 8px 8px 0;
        font-size: 0.85rem;
        line-height: 1.65;
        color: #334155;
        margin-bottom: 26px;
    }
    .tpl-executive .exec-sec-title {
        font-size: 1.05rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #0f172a;
        border-bottom: 2px solid #0284c7;
        padding-bottom: 6px;
        margin-bottom: 16px;
    }
    .tpl-executive .exec-exp-header {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
    }
    .tpl-executive .exec-exp-role {
        font-size: 0.96rem;
        font-weight: 700;
        color: #0f172a;
    }
    .tpl-executive .exec-exp-company {
        font-size: 0.88rem;
        font-weight: 600;
        color: #0284c7;
        margin-bottom: 4px;
    }
    .tpl-executive .exec-exp-date {
        font-size: 0.78rem;
        font-weight: 600;
        color: #64748b;
    }
    .tpl-executive .exec-bullets {
        margin: 6px 0 16px 16px;
        font-size: 0.84rem;
        line-height: 1.6;
        color: #334155;
    }

    /* ========================================================
       TEMPLATE 3: MINIMALIST TECH (tpl-minimalist)
       ======================================================== */
    .tpl-minimalist {
        padding: 42px 46px;
        font-family: 'Inter', sans-serif;
        color: #111827;
    }
    .tpl-minimalist .tech-header {
        border-bottom: 1px solid #111827;
        padding-bottom: 18px;
        margin-bottom: 22px;
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
    }
    .tpl-minimalist .tech-name {
        font-size: 2.2rem;
        font-weight: 800;
        letter-spacing: -0.04em;
        margin: 0;
        color: #000;
    }
    .tpl-minimalist .tech-title {
        font-family: 'Fira Code', monospace;
        font-size: 0.95rem;
        color: #4b5563;
        font-weight: 600;
        margin: 4px 0 0;
    }
    .tpl-minimalist .tech-contacts {
        font-family: 'Fira Code', monospace;
        font-size: 0.76rem;
        color: #4b5563;
        text-align: right;
        line-height: 1.6;
    }
    .tpl-minimalist .tech-sec-title {
        font-family: 'Fira Code', monospace;
        font-size: 0.85rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #111827;
        background: #f3f4f6;
        padding: 4px 8px;
        border-left: 3px solid #111827;
        margin-bottom: 12px;
    }
    .tpl-minimalist .tech-exp-row {
        display: flex;
        justify-content: space-between;
        font-weight: 700;
        font-size: 0.92rem;
    }
    .tpl-minimalist .tech-exp-sub {
        display: flex;
        justify-content: space-between;
        font-size: 0.82rem;
        color: #4b5563;
        margin-bottom: 4px;
    }
    .tpl-minimalist .tech-bullets {
        margin: 4px 0 14px 16px;
        font-size: 0.84rem;
        line-height: 1.6;
        color: #374151;
    }
    .tpl-minimalist .tech-tag {
        font-family: 'Fira Code', monospace;
        background: #f3f4f6;
        border: 1px solid #e5e7eb;
        color: #1f2937;
        font-size: 0.75rem;
        padding: 2px 7px;
        border-radius: 4px;
        display: inline-block;
        margin: 2px 3px 2px 0;
    }

    /* ── Notification Toast ── */
    .cv-toast {
        position: fixed;
        bottom: 24px;
        right: 24px;
        background: #0f172a;
        color: white;
        padding: 14px 20px;
        border-radius: 12px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.25);
        font-size: 0.88rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 10px;
        z-index: 9999;
        transform: translateY(100px);
        opacity: 0;
        transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    .cv-toast.show {
        transform: translateY(0);
        opacity: 1;
    }

    /* ── PRINT & PDF EXPORT CSS RULES ── */
    @media print {
        @page {
            size: A4 portrait;
            margin: 0;
        }
        body {
            background: white !important;
            padding: 0 !important;
            margin: 0 !important;
            color: #000 !important;
        }
        .navbar, 
        .cv-app-header, 
        .cv-sidebar-tools, 
        .cv-workbench-toolbar, 
        .cv-toast, 
        .modal, 
        .btn-section-enhance, 
        .cv-item-actions, 
        .btn-add-skill-inline, 
        .skill-pill-del,
        .site-footer,
        .ai-chat-widget,
        #toastContainer {
            display: none !important;
        }
        .cv-workspace {
            padding: 0 !important;
            margin: 0 !important;
        }
        .container, .container-fluid {
            max-width: 100% !important;
            padding: 0 !important;
            margin: 0 !important;
        }
        .cv-a4-sheet-container {
            padding: 0 !important;
            margin: 0 !important;
            overflow: visible !important;
        }
        .cv-a4-sheet {
            width: 210mm !important;
            min-height: 297mm !important;
            box-shadow: none !important;
            border-radius: 0 !important;
            transform: none !important;
            margin: 0 auto !important;
        }
    }
</style>

<!-- Workspace Header -->
<div class="cv-app-header">
    <div class="container">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <span class="cv-app-badge">
                    <i class="fas fa-wand-magic-sparkles"></i>
                    <?php echo ai_llm_available() ? 'AI Powered by ' . ai_provider_label() : 'NovaHire Intelligent Hybrid Engine'; ?>
                </span>
                <h1 class="cv-app-title">Automated AI CV Generator</h1>
                <p class="cv-app-subtitle">Build, customize with live inline editing, tailor to any job vacancy, and export an ATS-optimized CV.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="ai_hub.php" class="btn btn-outline-light rounded-pill px-3">
                    <i class="fas fa-arrow-left mr-1"></i> Back to Hub
                </a>
                <button type="button" class="btn btn-outline-light rounded-pill px-3" data-toggle="modal" data-target="#savedCvsModal">
                    <i class="fas fa-folder-open mr-1"></i> My Saved CVs
                </button>
                <a href="view_cv.php" class="btn btn-light rounded-pill px-3 text-dark font-weight-bold">
                    <i class="fas fa-eye mr-1"></i> Static Profile CV
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container cv-workspace">
    <div class="row">
        <!-- ── Left Control & Tooling Panel ── -->
        <div class="col-lg-4 mb-4">
            <div class="cv-sidebar-tools">

                <!-- Target Job Customizer -->
                <div class="cv-tool-card">
                    <div class="cv-tool-heading">
                        <span><i class="fas fa-crosshairs mr-2"></i>Target Job Customization</span>
                    </div>
                    <p class="text-muted small mb-2">Select an active job to tailor keywords, bullet points, and competencies specifically for that vacancy.</p>
                    <select id="targetJobSelect" class="form-control mb-3 font-weight-medium" style="font-size: 0.85rem; border-radius: 10px;">
                        <option value="">-- General CV (Profile Heuristics) --</option>
                        <?php foreach ($jobs as $j): ?>
                            <option value="<?php echo $j['id']; ?>" <?php echo ($selected_job_id == $j['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($j['job_title'] . ' — ' . $j['company_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" id="btnGenerateAi" class="btn-generate-ai">
                        <i class="fas fa-wand-magic-sparkles"></i>
                        <span>Generate with AI</span>
                    </button>
                </div>

                <!-- Template Selector -->
                <div class="cv-tool-card">
                    <div class="cv-tool-heading">
                        <span><i class="fas fa-palette mr-2"></i>ATS Template Style</span>
                    </div>
                    <div class="template-picker-grid">
                        <div class="tpl-card <?php echo $selected_template === 'modern' ? 'active' : ''; ?>" data-tpl="modern">
                            <div class="tpl-card-icon"><i class="fas fa-layer-group"></i></div>
                            <div class="tpl-card-name">Modern Clean</div>
                            <div class="tpl-card-tag">High ATS Score</div>
                        </div>
                        <div class="tpl-card <?php echo $selected_template === 'executive' ? 'active' : ''; ?>" data-tpl="executive">
                            <div class="tpl-card-icon"><i class="fas fa-columns"></i></div>
                            <div class="tpl-card-name">Executive</div>
                            <div class="tpl-card-tag">2-Column Dark</div>
                        </div>
                        <div class="tpl-card <?php echo $selected_template === 'minimalist' ? 'active' : ''; ?>" data-tpl="minimalist">
                            <div class="tpl-card-icon"><i class="fas fa-code"></i></div>
                            <div class="tpl-card-name">Minimalist Tech</div>
                            <div class="tpl-card-tag">Engineering Focus</div>
                        </div>
                    </div>
                </div>

                <!-- Add Custom Section Items -->
                <div class="cv-tool-card">
                    <div class="cv-tool-heading">
                        <span><i class="fas fa-plus-circle mr-2"></i>Add Content</span>
                    </div>
                    <div class="quick-add-grid">
                        <button type="button" class="btn-quick-add" onclick="aiCvApp.addExperienceItem()">
                            <i class="fas fa-briefcase"></i> + Experience
                        </button>
                        <button type="button" class="btn-quick-add" onclick="aiCvApp.addProjectItem()">
                            <i class="fas fa-laptop-code"></i> + Project
                        </button>
                        <button type="button" class="btn-quick-add" onclick="aiCvApp.addEducationItem()">
                            <i class="fas fa-graduation-cap"></i> + Education
                        </button>
                        <button type="button" class="btn-quick-add" onclick="aiCvApp.promptAddSkill()">
                            <i class="fas fa-tags"></i> + Skill
                        </button>
                    </div>
                </div>

                <!-- Actions: Save, Export, Reset -->
                <div class="cv-tool-card">
                    <div class="cv-tool-heading">
                        <span><i class="fas fa-sliders mr-2"></i>Actions & Export</span>
                    </div>
                    <div class="cv-action-stack">
                        <button type="button" id="btnSaveCv" class="btn-save-cv" onclick="aiCvApp.saveCv()">
                            <i class="fas fa-floppy-disk"></i> Save to Database
                        </button>
                        <button type="button" class="btn-export-pdf" onclick="aiCvApp.exportPdf()">
                            <i class="fas fa-file-pdf"></i> Download PDF
                        </button>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn-secondary-action flex-fill" onclick="window.print()">
                                <i class="fas fa-print"></i> Print
                            </button>
                            <button type="button" class="btn-secondary-action flex-fill" onclick="aiCvApp.resetToProfile()">
                                <i class="fas fa-rotate-left"></i> Reset
                            </button>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- ── Right Live Interactive A4 Preview Canvas ── -->
        <div class="col-lg-8">
            <div class="cv-preview-workbench">
                
                <!-- Canvas Control Bar -->
                <div class="cv-workbench-toolbar">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge badge-primary px-2 py-1" style="font-size:0.75rem; border-radius:6px;" id="cvModeBadge">
                            <i class="fas fa-check-circle mr-1"></i> Interactive Live Mode
                        </span>
                        <small class="text-muted" style="font-size:0.78rem;">Click any text on the A4 page below to edit directly.</small>
                    </div>
                    <div class="canvas-scale-tools">
                        <button type="button" class="btn-zoom" onclick="aiCvApp.setZoom(0.85)">85%</button>
                        <button type="button" class="btn-zoom" onclick="aiCvApp.setZoom(1.0)">100%</button>
                    </div>
                </div>

                <!-- Physical A4 Canvas Sheet -->
                <div class="cv-a4-sheet-container">
                    <div id="cvA4Sheet" class="cv-a4-sheet">
                        <!-- Dynamically populated by aiCvApp.renderCv() -->
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Saved CVs Modal -->
<div class="modal fade" id="savedCvsModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content" style="border-radius: 18px; border: none; box-shadow: var(--cv-shadow);">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title font-weight-bold"><i class="fas fa-folder-open text-primary mr-2"></i>My Saved CVs</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div id="savedCvsListContainer">
                    <div class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin mr-2"></i>Loading saved CVs...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Notification Toast -->
<div id="cvToast" class="cv-toast">
    <i class="fas fa-info-circle"></i>
    <span id="cvToastMsg">Action completed</span>
</div>

<!-- Initial CV Data Payload -->
<script id="initialCvData" type="application/json">
<?php echo $initial_cv_json; ?>
</script>

<script>
/**
 * NovaHire AI CV Generator Client Controller
 */
const aiCvApp = {
    cvData: null,
    activeTemplate: '<?php echo $selected_template; ?>',
    currentCvId: <?php echo intval($initial_cv['cv_id'] ?? 0); ?>,
    scale: 1.0,

    init() {
        try {
            const raw = document.getElementById('initialCvData').textContent;
            this.cvData = JSON.parse(raw);
        } catch (e) {
            console.error("Failed to parse initial CV data:", e);
            this.cvData = {
                full_name: "<?php echo htmlspecialchars($user['username'] ?? 'Professional'); ?>",
                headline: "Software Engineer",
                email: "<?php echo htmlspecialchars($user['email'] ?? ''); ?>",
                phone: "<?php echo htmlspecialchars($user['phone'] ?? ''); ?>",
                location: "Dhaka, Bangladesh",
                summary: "Motivated and results-driven professional.",
                skills: { technical: ["PHP", "MySQL", "JavaScript"], soft: ["Problem Solving"], tools: ["Git"] },
                experience: [],
                education: [],
                projects: []
            };
        }

        this.bindEvents();
        this.renderCv();
    },

    bindEvents() {
        const self = this;

        // Template Picker
        document.querySelectorAll('.tpl-card').forEach(card => {
            card.addEventListener('click', function() {
                document.querySelectorAll('.tpl-card').forEach(c => c.classList.remove('active'));
                this.classList.add('active');
                self.activeTemplate = this.dataset.tpl;
                self.renderCv();
                self.showToast('Template switched to: ' + self.activeTemplate.toUpperCase());
            });
        });

        // Generate with AI
        document.getElementById('btnGenerateAi').addEventListener('click', () => {
            self.generateWithAi();
        });

        // Load saved CV list modal hook
        $('#savedCvsModal').on('show.bs.modal', function () {
            self.fetchSavedCvsList();
        });
    },

    setZoom(val) {
        this.scale = val;
        const sheet = document.getElementById('cvA4Sheet');
        if (sheet) {
            sheet.style.transform = `scale(${val})`;
            sheet.style.marginBottom = val < 1 ? `-${(1 - val) * 297}mm` : '0';
        }
    },

    showToast(msg) {
        const toast = document.getElementById('cvToast');
        const msgEl = document.getElementById('cvToastMsg');
        if (toast && msgEl) {
            msgEl.textContent = msg;
            toast.classList.add('show');
            setTimeout(() => { toast.classList.remove('show'); }, 3500);
        }
    },

    // ─────────────────────────────────────────────────────────────
    // RENDER ENGINE: Builds the A4 Page DOM based on active template
    // ─────────────────────────────────────────────────────────────
    renderCv() {
        const container = document.getElementById('cvA4Sheet');
        if (!container) return;

        // Clear classes and attach active template class
        container.className = 'cv-a4-sheet tpl-' + this.activeTemplate;
        
        let html = '';
        if (this.activeTemplate === 'executive') {
            html = this.renderExecutiveTemplate();
        } else if (this.activeTemplate === 'minimalist') {
            html = this.renderMinimalistTemplate();
        } else {
            html = this.renderModernTemplate();
        }

        container.innerHTML = html;
        this.attachInlineEditSync();
    },

    // ── TEMPLATE 1: MODERN CLEAN ──
    renderModernTemplate() {
        const d = this.cvData;
        let techSkills = [];
        let softSkills = [];
        let tools      = [];

        if (d.skills) {
            if (Array.isArray(d.skills)) {
                techSkills = d.skills;
            } else {
                techSkills = d.skills.technical || [];
                softSkills = d.skills.soft || [];
                tools      = d.skills.tools || [];
            }
        }

        let expHtml = '';
        (d.experience || []).forEach((exp, eIdx) => {
            let bulletsHtml = '';
            (exp.bullets || []).forEach((b, bIdx) => {
                bulletsHtml += `<li class="cv-interactive-item">
                    <span contenteditable="true" data-path="experience.${eIdx}.bullets.${bIdx}">${this.escapeHtml(b)}</span>
                    <span class="cv-item-actions">
                        <button class="btn-cv-micro" onclick="aiCvApp.enhanceBullet('experience', ${eIdx}, ${bIdx})">✨ Polish</button>
                        <button class="btn-cv-micro btn-micro-danger" onclick="aiCvApp.deleteBullet('experience', ${eIdx}, ${bIdx})">×</button>
                    </span>
                </li>`;
            });

            expHtml += `<div class="cv-interactive-item mb-3">
                <div class="cv-item-actions">
                    <button class="btn-cv-micro" onclick="aiCvApp.addBulletToItem('experience', ${eIdx})">+ Bullet</button>
                    <button class="btn-cv-micro btn-micro-danger" onclick="aiCvApp.deleteItem('experience', ${eIdx})"><i class="fas fa-trash"></i></button>
                </div>
                <div class="d-flex justify-content-between align-items-baseline">
                    <span class="cv-exp-role" contenteditable="true" data-path="experience.${eIdx}.role">${this.escapeHtml(exp.role || exp.title || 'Role Title')}</span>
                    <span class="cv-exp-meta" contenteditable="true" data-path="experience.${eIdx}.period">${this.escapeHtml(exp.period || exp.date || '2022 - Present')}</span>
                </div>
                <div class="d-flex justify-content-between align-items-baseline">
                    <span class="cv-exp-company" contenteditable="true" data-path="experience.${eIdx}.company">${this.escapeHtml(exp.company || 'Company Name')}</span>
                    <span class="cv-exp-meta" contenteditable="true" data-path="experience.${eIdx}.location">${this.escapeHtml(exp.location || '')}</span>
                </div>
                <ul class="cv-bullets">${bulletsHtml}</ul>
            </div>`;
        });

        let projHtml = '';
        (d.projects || []).forEach((p, pIdx) => {
            let bulletsHtml = '';
            (p.bullets || []).forEach((b, bIdx) => {
                bulletsHtml += `<li class="cv-interactive-item">
                    <span contenteditable="true" data-path="projects.${pIdx}.bullets.${bIdx}">${this.escapeHtml(b)}</span>
                    <span class="cv-item-actions">
                        <button class="btn-cv-micro" onclick="aiCvApp.enhanceBullet('projects', ${pIdx}, ${bIdx})">✨ Polish</button>
                        <button class="btn-cv-micro btn-micro-danger" onclick="aiCvApp.deleteBullet('projects', ${pIdx}, ${bIdx})">×</button>
                    </span>
                </li>`;
            });

            projHtml += `<div class="cv-interactive-item mb-3">
                <div class="cv-item-actions">
                    <button class="btn-cv-micro" onclick="aiCvApp.addBulletToItem('projects', ${pIdx})">+ Bullet</button>
                    <button class="btn-cv-micro btn-micro-danger" onclick="aiCvApp.deleteItem('projects', ${pIdx})"><i class="fas fa-trash"></i></button>
                </div>
                <div class="d-flex justify-content-between align-items-baseline">
                    <strong style="font-size:0.92rem; color:#0f172a;" contenteditable="true" data-path="projects.${pIdx}.title">${this.escapeHtml(p.title || p.name || 'Project Title')}</strong>
                    <span class="cv-exp-meta" contenteditable="true" data-path="projects.${pIdx}.tech_stack">${this.escapeHtml(p.tech_stack || '')}</span>
                </div>
                <ul class="cv-bullets">${bulletsHtml}</ul>
            </div>`;
        });

        let eduHtml = '';
        (d.education || []).forEach((edu, edIdx) => {
            eduHtml += `<div class="cv-interactive-item mb-3">
                <div class="cv-item-actions">
                    <button class="btn-cv-micro btn-micro-danger" onclick="aiCvApp.deleteItem('education', ${edIdx})"><i class="fas fa-trash"></i></button>
                </div>
                <div style="font-weight:700; font-size:0.88rem; color:#0f172a;" contenteditable="true" data-path="education.${edIdx}.degree">${this.escapeHtml(edu.degree || 'Degree')}</div>
                <div style="font-size:0.82rem; color:#4f46e5;" contenteditable="true" data-path="education.${edIdx}.institution">${this.escapeHtml(edu.institution || 'University')}</div>
                <div style="font-size:0.75rem; color:#64748b;" contenteditable="true" data-path="education.${edIdx}.year">${this.escapeHtml(edu.year || '2019 - 2023')}</div>
                <p style="font-size:0.8rem; color:#475569; margin:4px 0 0;" contenteditable="true" data-path="education.${edIdx}.details">${this.escapeHtml(edu.details || '')}</p>
            </div>`;
        });

        return `
        <div class="cv-header">
            <h1 class="cv-name" contenteditable="true" data-path="full_name">${this.escapeHtml(d.full_name || 'Candidate Name')}</h1>
            <div class="d-flex align-items-center justify-content-between">
                <div class="cv-title" contenteditable="true" data-path="headline">${this.escapeHtml(d.headline || 'Professional')}</div>
                <button class="btn-section-enhance" onclick="aiCvApp.enhanceSection('headline')"><i class="fas fa-sparkles"></i> AI Elevate</button>
            </div>
            <div class="cv-contacts">
                <span><i class="fas fa-envelope"></i> <span contenteditable="true" data-path="email">${this.escapeHtml(d.email || '')}</span></span>
                <span><i class="fas fa-phone"></i> <span contenteditable="true" data-path="phone">${this.escapeHtml(d.phone || '')}</span></span>
                <span><i class="fas fa-map-marker-alt"></i> <span contenteditable="true" data-path="location">${this.escapeHtml(d.location || 'Dhaka, Bangladesh')}</span></span>
            </div>
        </div>

        <div class="cv-body-grid">
            <div class="cv-main-col">
                <div class="mb-4">
                    <div class="section-header-wrap">
                        <div class="sec-title">Professional Summary</div>
                        <button class="btn-section-enhance" onclick="aiCvApp.enhanceSection('summary')"><i class="fas fa-wand-magic-sparkles"></i> AI Polish</button>
                    </div>
                    <div class="cv-summary-text" contenteditable="true" data-path="summary">${this.escapeHtml(d.summary || '')}</div>
                </div>

                <div class="mb-4">
                    <div class="section-header-wrap">
                        <div class="sec-title">Work Experience</div>
                        <button class="btn-section-enhance" onclick="aiCvApp.addExperienceItem()">+ Add Role</button>
                    </div>
                    ${expHtml || '<p class="text-muted small">No experience listed yet. Click + Add Role.</p>'}
                </div>

                <div>
                    <div class="section-header-wrap">
                        <div class="sec-title">Key Projects</div>
                        <button class="btn-section-enhance" onclick="aiCvApp.addProjectItem()">+ Add Project</button>
                    </div>
                    ${projHtml || '<p class="text-muted small">No projects listed yet. Click + Add Project.</p>'}
                </div>
            </div>

            <div class="cv-side-col">
                <div class="mb-4">
                    <div class="sec-title">Technical Skills</div>
                    <div class="d-flex flex-wrap align-items-center">
                        ${this.renderSkillsList(techSkills, 'technical')}
                    </div>
                </div>

                <div class="mb-4">
                    <div class="sec-title">Soft Skills</div>
                    <div class="d-flex flex-wrap align-items-center">
                        ${this.renderSkillsList(softSkills, 'soft')}
                    </div>
                </div>

                <div class="mb-4">
                    <div class="sec-title">Tools & Platforms</div>
                    <div class="d-flex flex-wrap align-items-center">
                        ${this.renderSkillsList(tools, 'tools')}
                    </div>
                </div>

                <div>
                    <div class="sec-title">Education</div>
                    ${eduHtml}
                </div>
            </div>
        </div>`;
    },

    // ── TEMPLATE 2: EXECUTIVE TWO-COLUMN ──
    renderExecutiveTemplate() {
        const d = this.cvData;
        let techSkills = [];
        let softSkills = [];
        let tools      = [];

        if (d.skills) {
            if (Array.isArray(d.skills)) {
                techSkills = d.skills;
            } else {
                techSkills = d.skills.technical || [];
                softSkills = d.skills.soft || [];
                tools      = d.skills.tools || [];
            }
        }

        let expHtml = '';
        (d.experience || []).forEach((exp, eIdx) => {
            let bulletsHtml = '';
            (exp.bullets || []).forEach((b, bIdx) => {
                bulletsHtml += `<li class="cv-interactive-item">
                    <span contenteditable="true" data-path="experience.${eIdx}.bullets.${bIdx}">${this.escapeHtml(b)}</span>
                    <span class="cv-item-actions">
                        <button class="btn-cv-micro" onclick="aiCvApp.enhanceBullet('experience', ${eIdx}, ${bIdx})">✨ Polish</button>
                        <button class="btn-cv-micro btn-micro-danger" onclick="aiCvApp.deleteBullet('experience', ${eIdx}, ${bIdx})">×</button>
                    </span>
                </li>`;
            });

            expHtml += `<div class="cv-interactive-item mb-3">
                <div class="cv-item-actions">
                    <button class="btn-cv-micro" onclick="aiCvApp.addBulletToItem('experience', ${eIdx})">+ Bullet</button>
                    <button class="btn-cv-micro btn-micro-danger" onclick="aiCvApp.deleteItem('experience', ${eIdx})"><i class="fas fa-trash"></i></button>
                </div>
                <div class="exec-exp-header">
                    <span class="exec-exp-role" contenteditable="true" data-path="experience.${eIdx}.role">${this.escapeHtml(exp.role || exp.title || 'Role')}</span>
                    <span class="exec-exp-date" contenteditable="true" data-path="experience.${eIdx}.period">${this.escapeHtml(exp.period || exp.date || '')}</span>
                </div>
                <div class="exec-exp-company" contenteditable="true" data-path="experience.${eIdx}.company">${this.escapeHtml(exp.company || 'Company')}</div>
                <ul class="exec-bullets">${bulletsHtml}</ul>
            </div>`;
        });

        let projHtml = '';
        (d.projects || []).forEach((p, pIdx) => {
            let bulletsHtml = '';
            (p.bullets || []).forEach((b, bIdx) => {
                bulletsHtml += `<li class="cv-interactive-item">
                    <span contenteditable="true" data-path="projects.${pIdx}.bullets.${bIdx}">${this.escapeHtml(b)}</span>
                    <span class="cv-item-actions">
                        <button class="btn-cv-micro" onclick="aiCvApp.enhanceBullet('projects', ${pIdx}, ${bIdx})">✨ Polish</button>
                        <button class="btn-cv-micro btn-micro-danger" onclick="aiCvApp.deleteBullet('projects', ${pIdx}, ${bIdx})">×</button>
                    </span>
                </li>`;
            });

            projHtml += `<div class="cv-interactive-item mb-3">
                <div class="cv-item-actions">
                    <button class="btn-cv-micro btn-micro-danger" onclick="aiCvApp.deleteItem('projects', ${pIdx})"><i class="fas fa-trash"></i></button>
                </div>
                <div class="d-flex justify-content-between align-items-baseline">
                    <strong style="font-size:0.92rem;" contenteditable="true" data-path="projects.${pIdx}.title">${this.escapeHtml(p.title || p.name || 'Project')}</strong>
                    <small class="text-muted" contenteditable="true" data-path="projects.${pIdx}.tech_stack">${this.escapeHtml(p.tech_stack || '')}</small>
                </div>
                <ul class="exec-bullets">${bulletsHtml}</ul>
            </div>`;
        });

        let eduHtml = '';
        (d.education || []).forEach((edu, edIdx) => {
            eduHtml += `<div class="cv-interactive-item mb-3">
                <div style="font-weight:700; font-size:0.84rem; color:#f8fafc;" contenteditable="true" data-path="education.${edIdx}.degree">${this.escapeHtml(edu.degree || '')}</div>
                <div style="font-size:0.78rem; color:#94a3b8;" contenteditable="true" data-path="education.${edIdx}.institution">${this.escapeHtml(edu.institution || '')}</div>
                <div style="font-size:0.72rem; color:#64748b;" contenteditable="true" data-path="education.${edIdx}.year">${this.escapeHtml(edu.year || '')}</div>
            </div>`;
        });

        const initials = (d.full_name || 'NH').split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();

        return `
        <div class="exec-sidebar">
            <div class="exec-avatar-badge">${initials}</div>
            
            <div class="exec-side-sec">
                <div class="exec-side-title">Contact</div>
                <div class="exec-contact-item"><i class="fas fa-envelope"></i> <span contenteditable="true" data-path="email">${this.escapeHtml(d.email || '')}</span></div>
                <div class="exec-contact-item"><i class="fas fa-phone"></i> <span contenteditable="true" data-path="phone">${this.escapeHtml(d.phone || '')}</span></div>
                <div class="exec-contact-item"><i class="fas fa-map-marker-alt"></i> <span contenteditable="true" data-path="location">${this.escapeHtml(d.location || 'Dhaka, Bangladesh')}</span></div>
            </div>

            <div class="exec-side-sec">
                <div class="exec-side-title">Technical Expertise</div>
                <div class="d-flex flex-wrap align-items-center">
                    ${this.renderSkillsList(techSkills, 'technical')}
                </div>
            </div>

            <div class="exec-side-sec">
                <div class="exec-side-title">Core Competencies</div>
                <div class="d-flex flex-wrap align-items-center">
                    ${this.renderSkillsList(softSkills, 'soft')}
                </div>
            </div>

            <div class="exec-side-sec">
                <div class="exec-side-title">Education</div>
                ${eduHtml}
            </div>
        </div>

        <div class="exec-main">
            <h1 class="exec-name" contenteditable="true" data-path="full_name">${this.escapeHtml(d.full_name || 'Candidate Name')}</h1>
            <div class="d-flex justify-content-between align-items-center">
                <div class="exec-title" contenteditable="true" data-path="headline">${this.escapeHtml(d.headline || 'Executive Title')}</div>
                <button class="btn-section-enhance" onclick="aiCvApp.enhanceSection('headline')">✨ Elevate</button>
            </div>

            <div class="exec-summary-box">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <strong style="text-transform:uppercase; font-size:0.75rem; color:#0284c7; letter-spacing:1px;">Executive Profile</strong>
                    <button class="btn-section-enhance" onclick="aiCvApp.enhanceSection('summary')">✨ Polish</button>
                </div>
                <div contenteditable="true" data-path="summary">${this.escapeHtml(d.summary || '')}</div>
            </div>

            <div class="mb-4">
                <div class="section-header-wrap">
                    <div class="exec-sec-title">Work Experience</div>
                    <button class="btn-section-enhance" onclick="aiCvApp.addExperienceItem()">+ Add Role</button>
                </div>
                ${expHtml}
            </div>

            <div>
                <div class="section-header-wrap">
                    <div class="exec-sec-title">Featured Projects</div>
                    <button class="btn-section-enhance" onclick="aiCvApp.addProjectItem()">+ Add Project</button>
                </div>
                ${projHtml}
            </div>
        </div>`;
    },

    // ── TEMPLATE 3: MINIMALIST TECH ──
    renderMinimalistTemplate() {
        const d = this.cvData;
        let techSkills = [];
        let softSkills = [];
        let tools      = [];

        if (d.skills) {
            if (Array.isArray(d.skills)) {
                techSkills = d.skills;
            } else {
                techSkills = d.skills.technical || [];
                softSkills = d.skills.soft || [];
                tools      = d.skills.tools || [];
            }
        }

        let expHtml = '';
        (d.experience || []).forEach((exp, eIdx) => {
            let bulletsHtml = '';
            (exp.bullets || []).forEach((b, bIdx) => {
                bulletsHtml += `<li class="cv-interactive-item">
                    <span contenteditable="true" data-path="experience.${eIdx}.bullets.${bIdx}">${this.escapeHtml(b)}</span>
                    <span class="cv-item-actions">
                        <button class="btn-cv-micro" onclick="aiCvApp.enhanceBullet('experience', ${eIdx}, ${bIdx})">✨</button>
                        <button class="btn-cv-micro btn-micro-danger" onclick="aiCvApp.deleteBullet('experience', ${eIdx}, ${bIdx})">×</button>
                    </span>
                </li>`;
            });

            expHtml += `<div class="cv-interactive-item mb-3">
                <div class="cv-item-actions">
                    <button class="btn-cv-micro" onclick="aiCvApp.addBulletToItem('experience', ${eIdx})">+ Bullet</button>
                    <button class="btn-cv-micro btn-micro-danger" onclick="aiCvApp.deleteItem('experience', ${eIdx})"><i class="fas fa-trash"></i></button>
                </div>
                <div class="tech-exp-row">
                    <span contenteditable="true" data-path="experience.${eIdx}.role">${this.escapeHtml(exp.role || exp.title || 'Role')}</span>
                    <span style="font-family:'Fira Code', monospace; font-size:0.8rem; color:#4b5563;" contenteditable="true" data-path="experience.${eIdx}.period">${this.escapeHtml(exp.period || exp.date || '')}</span>
                </div>
                <div class="tech-exp-sub">
                    <span contenteditable="true" data-path="experience.${eIdx}.company">${this.escapeHtml(exp.company || 'Company')}</span>
                    <span contenteditable="true" data-path="experience.${eIdx}.location">${this.escapeHtml(exp.location || '')}</span>
                </div>
                <ul class="tech-bullets">${bulletsHtml}</ul>
            </div>`;
        });

        let projHtml = '';
        (d.projects || []).forEach((p, pIdx) => {
            let bulletsHtml = '';
            (p.bullets || []).forEach((b, bIdx) => {
                bulletsHtml += `<li class="cv-interactive-item">
                    <span contenteditable="true" data-path="projects.${pIdx}.bullets.${bIdx}">${this.escapeHtml(b)}</span>
                    <span class="cv-item-actions">
                        <button class="btn-cv-micro" onclick="aiCvApp.enhanceBullet('projects', ${pIdx}, ${bIdx})">✨</button>
                        <button class="btn-cv-micro btn-micro-danger" onclick="aiCvApp.deleteBullet('projects', ${pIdx}, ${bIdx})">×</button>
                    </span>
                </li>`;
            });

            projHtml += `<div class="cv-interactive-item mb-3">
                <div class="cv-item-actions">
                    <button class="btn-cv-micro btn-micro-danger" onclick="aiCvApp.deleteItem('projects', ${pIdx})"><i class="fas fa-trash"></i></button>
                </div>
                <div class="d-flex justify-content-between align-items-baseline">
                    <strong style="font-size:0.9rem;" contenteditable="true" data-path="projects.${pIdx}.title">${this.escapeHtml(p.title || p.name || 'Project')}</strong>
                    <span class="tech-tag" contenteditable="true" data-path="projects.${pIdx}.tech_stack">${this.escapeHtml(p.tech_stack || '')}</span>
                </div>
                <ul class="tech-bullets">${bulletsHtml}</ul>
            </div>`;
        });

        let eduHtml = '';
        (d.education || []).forEach((edu, edIdx) => {
            eduHtml += `<div class="cv-interactive-item mb-2">
                <div class="d-flex justify-content-between">
                    <strong style="font-size:0.86rem;" contenteditable="true" data-path="education.${edIdx}.degree">${this.escapeHtml(edu.degree || '')}</strong>
                    <span style="font-family:'Fira Code', monospace; font-size:0.75rem; color:#4b5563;" contenteditable="true" data-path="education.${edIdx}.year">${this.escapeHtml(edu.year || '')}</span>
                </div>
                <div style="font-size:0.8rem; color:#4b5563;" contenteditable="true" data-path="education.${edIdx}.institution">${this.escapeHtml(edu.institution || '')}</div>
            </div>`;
        });

        return `
        <div class="tech-header">
            <div>
                <h1 class="tech-name" contenteditable="true" data-path="full_name">${this.escapeHtml(d.full_name || 'Candidate')}</h1>
                <div class="tech-title" contenteditable="true" data-path="headline">${this.escapeHtml(d.headline || 'Developer')}</div>
            </div>
            <div class="tech-contacts">
                <div contenteditable="true" data-path="email">${this.escapeHtml(d.email || '')}</div>
                <div contenteditable="true" data-path="phone">${this.escapeHtml(d.phone || '')}</div>
                <div contenteditable="true" data-path="location">${this.escapeHtml(d.location || 'Dhaka, Bangladesh')}</div>
            </div>
        </div>

        <div class="mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <div class="tech-sec-title">01. Summary</div>
                <button class="btn-section-enhance" onclick="aiCvApp.enhanceSection('summary')">✨ AI Polish</button>
            </div>
            <p style="font-size:0.85rem; line-height:1.65; color:#374151;" contenteditable="true" data-path="summary">${this.escapeHtml(d.summary || '')}</p>
        </div>

        <div class="mb-4">
            <div class="tech-sec-title">02. Technical Skills</div>
            <div class="mb-2">
                <small class="text-muted font-weight-bold mr-2">LANGUAGES & FRAMEWORKS:</small>
                ${this.renderSkillsList(techSkills, 'technical')}
            </div>
            <div class="mb-2">
                <small class="text-muted font-weight-bold mr-2">TOOLS & INFRASTRUCTURE:</small>
                ${this.renderSkillsList(tools, 'tools')}
            </div>
        </div>

        <div class="mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <div class="tech-sec-title">03. Experience</div>
                <button class="btn-section-enhance" onclick="aiCvApp.addExperienceItem()">+ Role</button>
            </div>
            ${expHtml}
        </div>

        <div class="mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <div class="tech-sec-title">04. Engineering Projects</div>
                <button class="btn-section-enhance" onclick="aiCvApp.addProjectItem()">+ Project</button>
            </div>
            ${projHtml}
        </div>

        <div>
            <div class="tech-sec-title">05. Education</div>
            ${eduHtml}
        </div>`;
    },

    // ── Helper: Render interactive skill pills with delete & adder ──
    renderSkillsList(arr, categoryKey) {
        let pills = '';
        (arr || []).forEach((skill, sIdx) => {
            pills += `<span class="skill-pill-interactive">
                <span contenteditable="true" data-path="skills.${categoryKey}.${sIdx}">${this.escapeHtml(skill)}</span>
                <span class="skill-pill-del" onclick="aiCvApp.deleteSkill('${categoryKey}', ${sIdx})" title="Remove skill">&times;</span>
            </span>`;
        });
        pills += `<button type="button" class="btn-add-skill-inline" onclick="aiCvApp.promptAddSkill('${categoryKey}')">+ Add</button>`;
        return pills;
    },

    // ── Reactive Data Sync: Reads edits on blur/input into this.cvData ──
    attachInlineEditSync() {
        const self = this;
        document.querySelectorAll('#cvA4Sheet [contenteditable="true"]').forEach(el => {
            const syncHandler = function() {
                const path = this.dataset.path;
                if (path) {
                    self.setDeepValue(self.cvData, path, this.innerText.trim());
                }
            };
            el.addEventListener('input', syncHandler);
            el.addEventListener('blur', syncHandler);
        });
    },

    setDeepValue(obj, path, value) {
        const parts = path.split('.');
        let curr = obj;
        for (let i = 0; i < parts.length - 1; i++) {
            const p = parts[i];
            if (!curr[p]) curr[p] = isNaN(parts[i + 1]) ? {} : [];
            curr = curr[p];
        }
        curr[parts[parts.length - 1]] = value;
    },

    escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    },

    // ─────────────────────────────────────────────────────────────
    // INTERACTIVE ACTIONS: Add / Delete / Enhance
    // ─────────────────────────────────────────────────────────────
    addExperienceItem() {
        if (!this.cvData.experience) this.cvData.experience = [];
        this.cvData.experience.unshift({
            role: 'Software Engineer',
            company: 'Company / Organization Name',
            location: 'Dhaka, Bangladesh',
            period: '2023 - Present',
            bullets: [
                'Engineered core business features and enhanced backend service performance.',
                'Collaborated with product teams to design and deploy modern responsive interfaces.'
            ]
        });
        this.renderCv();
        this.showToast('New experience item added');
    },

    addProjectItem() {
        if (!this.cvData.projects) this.cvData.projects = [];
        this.cvData.projects.unshift({
            title: 'New High-Impact Project',
            tech_stack: 'PHP, MySQL, JavaScript, Bootstrap',
            bullets: [
                'Designed and deployed an automated web solution delivering 40% workflow improvement.'
            ]
        });
        this.renderCv();
        this.showToast('New project item added');
    },

    addEducationItem() {
        if (!this.cvData.education) this.cvData.education = [];
        this.cvData.education.push({
            degree: 'Degree / Certification',
            institution: 'University / Institute Name',
            year: '2019 - 2023',
            details: 'Coursework and academic milestones.'
        });
        this.renderCv();
        this.showToast('New education item added');
    },

    promptAddSkill(cat = 'technical') {
        const skill = prompt('Enter new skill name:', '');
        if (skill && skill.trim()) {
            if (!this.cvData.skills) this.cvData.skills = {};
            if (Array.isArray(this.cvData.skills)) {
                this.cvData.skills = { technical: this.cvData.skills, soft: [], tools: [] };
            }
            if (!this.cvData.skills[cat]) this.cvData.skills[cat] = [];
            this.cvData.skills[cat].push(skill.trim());
            this.renderCv();
            this.showToast(`Skill "${skill.trim()}" added to ${cat}`);
        }
    },

    deleteSkill(cat, idx) {
        if (this.cvData.skills && this.cvData.skills[cat]) {
            this.cvData.skills[cat].splice(idx, 1);
            this.renderCv();
        }
    },

    addBulletToItem(section, itemIdx) {
        if (this.cvData[section] && this.cvData[section][itemIdx]) {
            if (!this.cvData[section][itemIdx].bullets) this.cvData[section][itemIdx].bullets = [];
            this.cvData[section][itemIdx].bullets.push('Spearheaded key project initiatives and optimized operational workflows.');
            this.renderCv();
        }
    },

    deleteBullet(section, itemIdx, bIdx) {
        if (this.cvData[section] && this.cvData[section][itemIdx] && this.cvData[section][itemIdx].bullets) {
            this.cvData[section][itemIdx].bullets.splice(bIdx, 1);
            this.renderCv();
        }
    },

    deleteItem(section, idx) {
        if (this.cvData[section]) {
            this.cvData[section].splice(idx, 1);
            this.renderCv();
            this.showToast('Item deleted');
        }
    },

    // ─────────────────────────────────────────────────────────────
    // AI ENHANCEMENTS: API Calls
    // ─────────────────────────────────────────────────────────────
    enhanceSection(sectionType) {
        const originalText = this.cvData[sectionType] || '';
        const targetRole = this.cvData.headline || '';

        this.showToast(`Enhancing ${sectionType} with AI...`);

        const formData = new FormData();
        formData.append('action', 'enhance_section');
        formData.append('section_type', sectionType);
        formData.append('original_text', originalText);
        formData.append('target_role', targetRole);

        fetch('../api/ai_generate_cv.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.ok && data.enhanced_text) {
                this.cvData[sectionType] = data.enhanced_text;
                this.renderCv();
                this.showToast(`✨ ${sectionType.toUpperCase()} enhanced successfully!`);
            } else {
                alert(data.error || 'Failed to enhance section');
            }
        })
        .catch(err => {
            console.error(err);
            alert('Enhancement request failed. Please check network.');
        });
    },

    enhanceBullet(section, itemIdx, bIdx) {
        const bulletText = (this.cvData[section] && this.cvData[section][itemIdx] && this.cvData[section][itemIdx].bullets)
            ? this.cvData[section][itemIdx].bullets[bIdx] : '';

        this.showToast('Polishing bullet point with AI...');

        const formData = new FormData();
        formData.append('action', 'enhance_section');
        formData.append('section_type', 'bullet');
        formData.append('original_text', bulletText);
        formData.append('target_role', this.cvData.headline || '');

        fetch('../api/ai_generate_cv.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.ok && data.enhanced_text) {
                this.cvData[section][itemIdx].bullets[bIdx] = data.enhanced_text;
                this.renderCv();
                this.showToast('✨ Bullet point polished!');
            } else {
                alert(data.error || 'Failed to polish bullet point');
            }
        })
        .catch(err => {
            console.error(err);
            alert('Enhancement request failed.');
        });
    },

    generateWithAi() {
        const jobId = document.getElementById('targetJobSelect').value;
        const btn = document.getElementById('btnGenerateAi');
        const origBtnHtml = btn.innerHTML;

        btn.disabled = true;
        btn.innerHTML = `<i class="fas fa-spinner fa-spin mr-2"></i> Generating CV...`;
        this.showToast('Generating AI CV tailored to target profile...');

        const formData = new FormData();
        formData.append('action', 'generate');
        if (jobId) formData.append('job_id', jobId);

        fetch('../api/ai_generate_cv.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = origBtnHtml;

            if (data.ok && data.data) {
                this.cvData = data.data;
                this.renderCv();
                this.showToast('✨ CV Generated Successfully (' + (data.data.provider || 'AI Engine') + ')');
            } else {
                alert(data.error || 'Failed to generate CV');
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = origBtnHtml;
            console.error(err);
            alert('AI Generation request failed.');
        });
    },

    // ─────────────────────────────────────────────────────────────
    // PERSISTENCE: Save to Database & Load
    // ─────────────────────────────────────────────────────────────
    saveCv() {
        const saveBtn = document.getElementById('btnSaveCv');
        const origText = saveBtn.innerHTML;
        saveBtn.disabled = true;
        saveBtn.innerHTML = `<i class="fas fa-spinner fa-spin mr-1"></i> Saving...`;

        const jobId = document.getElementById('targetJobSelect').value;

        const formData = new FormData();
        formData.append('action', 'save');
        if (this.currentCvId > 0) formData.append('cv_id', this.currentCvId);
        if (jobId) formData.append('job_id', jobId);
        formData.append('template_name', this.activeTemplate);
        formData.append('full_name', this.cvData.full_name || '');
        formData.append('headline', this.cvData.headline || '');
        formData.append('email', this.cvData.email || '');
        formData.append('phone', this.cvData.phone || '');
        formData.append('location', this.cvData.location || '');
        formData.append('summary', this.cvData.summary || '');
        formData.append('skills_json', JSON.stringify(this.cvData.skills || {}));
        formData.append('experience_json', JSON.stringify(this.cvData.experience || []));
        formData.append('education_json', JSON.stringify(this.cvData.education || []));
        formData.append('projects_json', JSON.stringify(this.cvData.projects || []));

        fetch('../api/ai_generate_cv.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            saveBtn.disabled = false;
            saveBtn.innerHTML = origText;

            if (data.ok) {
                if (data.cv_id) this.currentCvId = data.cv_id;
                this.showToast('💾 ' + data.message);
            } else {
                alert(data.error || 'Failed to save CV');
            }
        })
        .catch(err => {
            saveBtn.disabled = false;
            saveBtn.innerHTML = origText;
            console.error(err);
            alert('Save request failed.');
        });
    },

    fetchSavedCvsList() {
        const container = document.getElementById('savedCvsListContainer');
        if (!container) return;

        container.innerHTML = '<div class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin mr-2"></i>Loading saved CVs...</div>';

        fetch('../api/ai_generate_cv.php?action=load')
        .then(res => res.json())
        .then(data => {
            if (data.ok && Array.isArray(data.list) && data.list.length > 0) {
                let html = '<div class="list-group">';
                data.list.forEach(cv => {
                    const jobBadge = cv.job_title ? `<span class="badge badge-info">${this.escapeHtml(cv.job_title)}</span>` : '<span class="badge badge-light">General CV</span>';
                    html += `
                    <div class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" style="border-radius:10px; margin-bottom:8px;">
                        <div>
                            <strong style="color:#0f172a;">${this.escapeHtml(cv.full_name)}</strong> &bull; <small class="text-muted">${this.escapeHtml(cv.headline || '')}</small>
                            <div class="mt-1 small text-muted">
                                ${jobBadge} &bull; Template: <strong>${cv.template_name}</strong> &bull; ${cv.updated_at}
                            </div>
                        </div>
                        <button class="btn btn-sm btn-primary rounded-pill px-3" onclick="aiCvApp.loadSavedCv(${cv.id})">
                            <i class="fas fa-download mr-1"></i> Load
                        </button>
                    </div>`;
                });
                html += '</div>';
                container.innerHTML = html;
            } else {
                container.innerHTML = '<div class="text-center py-4 text-muted"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No saved CV snapshots found yet. Click "Save to Database" to create one!</div>';
            }
        })
        .catch(err => {
            console.error(err);
            container.innerHTML = '<div class="text-danger py-3 text-center">Failed to load saved CVs.</div>';
        });
    },

    loadSavedCv(id) {
        fetch(`../api/ai_generate_cv.php?action=load&id=${id}`)
        .then(res => res.json())
        .then(data => {
            if (data.ok && data.cv) {
                this.cvData = {
                    full_name: data.cv.full_name,
                    headline: data.cv.headline,
                    email: data.cv.email,
                    phone: data.cv.phone,
                    location: data.cv.location,
                    summary: data.cv.summary,
                    skills: data.cv.skills,
                    experience: data.cv.experience,
                    education: data.cv.education,
                    projects: data.cv.projects,
                };
                this.currentCvId = data.cv.id;
                this.activeTemplate = data.cv.template_name || 'modern';
                
                // Update template selector UI
                document.querySelectorAll('.tpl-card').forEach(c => {
                    c.classList.toggle('active', c.dataset.tpl === this.activeTemplate);
                });

                if (data.cv.job_id) {
                    document.getElementById('targetJobSelect').value = data.cv.job_id;
                }

                $('#savedCvsModal').modal('hide');
                this.renderCv();
                this.showToast('📂 Saved CV snapshot loaded successfully!');
            } else {
                alert(data.error || 'Failed to load CV');
            }
        })
        .catch(err => {
            console.error(err);
            alert('Failed to load CV data.');
        });
    },

    resetToProfile() {
        if (confirm('Are you sure you want to reset all edits to your profile data?')) {
            window.location.href = 'ai_cv_generator.php';
        }
    },

    // ─────────────────────────────────────────────────────────────
    // EXPORT PDF: Client-side html2pdf / Browser Print
    // ─────────────────────────────────────────────────────────────
    exportPdf() {
        const element = document.getElementById('cvA4Sheet');
        if (!element) return;

        this.showToast('📄 Generating high-resolution PDF...');

        // Temporarily reset zoom for crisp export
        const oldTransform = element.style.transform;
        element.style.transform = 'none';

        const opt = {
            margin:       0,
            filename:     (this.cvData.full_name || 'Candidate').replace(/\s+/g, '_') + '_CV.pdf',
            image:        { type: 'jpeg', quality: 0.98 },
            html2canvas:  { scale: 2, useCORS: true, letterRendering: true },
            jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
        };

        if (typeof html2pdf !== 'undefined') {
            html2pdf().set(opt).from(element).save().then(() => {
                element.style.transform = oldTransform;
                this.showToast('✅ PDF Download Complete!');
            }).catch(err => {
                element.style.transform = oldTransform;
                console.error("html2pdf failed, falling back to window.print():", err);
                window.print();
            });
        } else {
            element.style.transform = oldTransform;
            window.print();
        }
    }
};

// Initialize on DOM Ready
document.addEventListener('DOMContentLoaded', () => {
    aiCvApp.init();
});
</script>

</body>
</html>
