<?php
// Core setup: session, DB, BASE_URL, helpers
require_once __DIR__ . '/../includes/bootstrap.php';
require_seeker_login();
require_once __DIR__ . '/../admin/dbcon.php';
global $con;

$user_id = (int)$_SESSION['id'];

// Fetch user info for PRO check
$sql = "SELECT is_pro, username FROM user_info WHERE id = ?";
$stmt = mysqli_prepare($con, $sql);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$user_data = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
$is_pro = isset($user_data['is_pro']) && $user_data['is_pro'] == 1;

// Fetch all categories
$all_cats_query = mysqli_query($con, "
    SELECT c.*, COUNT(s.id) as skill_count 
    FROM skill_categories c 
    LEFT JOIN skills s ON c.id = s.category_id 
    GROUP BY c.id 
    ORDER BY c.name ASC
");
$all_categories = [];
while ($cr = mysqli_fetch_assoc($all_cats_query)) {
    $all_categories[] = $cr;
}

// Fetch verified skills for current user
$vs_sql = "SELECT vs.*, s.name as skill_name, c.name as category_name, c.icon as category_icon
           FROM verified_skills vs
           JOIN skills s ON vs.skill_id = s.id
           JOIN skill_categories c ON s.category_id = c.id
           WHERE vs.user_id = ?
           ORDER BY vs.verified_at DESC";
$vs_stmt = mysqli_prepare($con, $vs_sql);
mysqli_stmt_bind_param($vs_stmt, "i", $user_id);
mysqli_stmt_execute($vs_stmt);
$user_verified_result = mysqli_stmt_get_result($vs_stmt);
$user_verified = [];
$user_verified_map = [];
while ($vr = mysqli_fetch_assoc($user_verified_result)) {
    $user_verified[] = $vr;
    $user_verified_map[$vr['skill_id']] = $vr;
}

// Fetch assessments with skill and category details
$assessments_sql = "
    SELECT 
        a.id as assessment_id, a.title as assessment_title, a.description as assessment_desc,
        a.time_limit, a.passing_score, a.is_premium, a.status,
        s.id as skill_id, s.name as skill_name, s.description as skill_desc,
        c.id as cat_id, c.name as cat_name, c.icon as cat_icon,
        (SELECT COUNT(*) FROM skill_assessment_questions q WHERE q.assessment_id = a.id) as question_count,
        (SELECT COUNT(*) FROM verified_skills vs2 WHERE vs2.skill_id = s.id) as verified_count
    FROM skill_assessments a
    JOIN skills s ON a.skill_id = s.id
    JOIN skill_categories c ON s.category_id = c.id
    WHERE a.status = 'active'
    ORDER BY a.is_premium ASC, c.name ASC, s.name ASC
";
$assessments_result = mysqli_query($con, $assessments_sql);
$assessments = [];
while ($ar = mysqli_fetch_assoc($assessments_result)) {
    $skill_id = $ar['skill_id'];
    $ar['is_user_verified'] = isset($user_verified_map[$skill_id]);
    $ar['user_verification'] = $user_verified_map[$skill_id] ?? null;
    $ar['duration_minutes'] = $ar['time_limit'] > 100 ? round($ar['time_limit'] / 60) : (int)$ar['time_limit'];
    
    // Assign difficulty
    if ($ar['passing_score'] >= 80) {
        $ar['difficulty'] = 'hard';
    } elseif ($ar['passing_score'] >= 70) {
        $ar['difficulty'] = 'medium';
    } else {
        $ar['difficulty'] = 'easy';
    }
    
    $assessments[] = $ar;
}

$total_assessments = count($assessments);
$total_user_verified = count($user_verified);

// Fetch top performers for the Leaderboard modal
$lb_sql = "
    SELECT vs.score, vs.skill_level, vs.verified_at, u.username, s.name as skill_name, c.name as category_name
    FROM verified_skills vs
    JOIN user_info u ON vs.user_id = u.id
    JOIN skills s ON vs.skill_id = s.id
    JOIN skill_categories c ON s.category_id = c.id
    ORDER BY vs.score DESC, vs.verified_at DESC
    LIMIT 10
";
$lb_result = mysqli_query($con, $lb_sql);
$leaderboard = [];
if ($lb_result) {
    while ($lbr = mysqli_fetch_assoc($lb_result)) {
        $leaderboard[] = $lbr;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ─── Ensure Bootstrap Navbar Options Are Fully Visible ─── */
.navbar-nav {
    display: flex !important;
}
@media (min-width: 992px) {
    #collapsibleNavbar.navbar-collapse {
        display: flex !important;
        visibility: visible !important;
    }
}

/* ─── Base Page Scoping ─── */
.sk-page {
    font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    color: #0f172a;
    background-color: #f8fafc;
    margin-top: 64px; /* clears fixed navbar */
    width: 100%;
    overflow-x: hidden;
}

.sk-container {
    max-width: 1152px;
    margin: 0 auto;
    padding: 0 20px;
    width: 100%;
}

@media (min-width: 1200px) {
    .sk-container {
        padding: 0 24px;
    }
}

/* ══════════════════════════════════════════════════════════════════════
   1. HERO BANNER (Exact Skill.Jobs Visuals)
══════════════════════════════════════════════════════════════════════ */
.sk-hero-wrap {
    position: relative;
    background: linear-gradient(135deg, #022859 0%, #03438C 50%, #01579B 100%);
    overflow: hidden;
    padding-top: 60px;
    padding-bottom: 70px;
}

.sk-hero-dots {
    position: absolute;
    inset: 0;
    opacity: 0.12;
    background-image: radial-gradient(circle, #ffffff 1.4px, transparent 0);
    background-size: 28px 28px;
    pointer-events: none;
}

.sk-hero-glow-1 {
    position: absolute;
    top: 20%;
    left: 4%;
    width: 360px;
    height: 360px;
    background: rgba(0, 173, 238, 0.25);
    border-radius: 50%;
    filter: blur(80px);
    pointer-events: none;
    animation: skGlowPulse 6s ease-in-out infinite;
}

.sk-hero-glow-2 {
    position: absolute;
    bottom: 10%;
    right: 4%;
    width: 400px;
    height: 400px;
    background: rgba(0, 173, 238, 0.20);
    border-radius: 50%;
    filter: blur(90px);
    pointer-events: none;
}

@keyframes skGlowPulse {
    0%, 100% { opacity: 0.35; transform: scale(1); }
    50% { opacity: 0.60; transform: scale(1.08); }
}

.sk-hero-flex {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: space-between;
    gap: 32px;
    position: relative;
    z-index: 2;
}

@media (min-width: 992px) {
    .sk-hero-flex {
        flex-direction: row;
        text-align: left;
    }
}

.sk-hero-text {
    text-align: center;
    max-width: 640px;
}

@media (min-width: 992px) {
    .sk-hero-text {
        text-align: left;
    }
}

.sk-hero-title {
    font-size: 2.3rem;
    font-weight: 800;
    line-height: 1.18;
    color: #ffffff;
    letter-spacing: -0.5px;
    margin-bottom: 16px;
}

@media (min-width: 768px) {
    .sk-hero-title {
        font-size: 3.2rem;
    }
}

.sk-hero-highlight {
    color: #fef08a;
}

.sk-hero-subtitle {
    font-size: 1.05rem;
    color: rgba(240, 249, 255, 0.9);
    line-height: 1.65;
    margin: 0 auto;
}

@media (min-width: 992px) {
    .sk-hero-subtitle {
        margin: 0;
    }
}

.sk-hero-actions {
    display: flex;
    flex-direction: column;
    gap: 12px;
    width: 100%;
}

@media (min-width: 576px) {
    .sk-hero-actions {
        flex-direction: row;
        width: auto;
        justify-content: center;
    }
}

.sk-btn-glass {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 13px 26px;
    border-radius: 9999px;
    background: rgba(255, 255, 255, 0.12);
    border: 1.5px solid rgba(255, 255, 255, 0.28);
    color: #ffffff !important;
    font-weight: 600;
    font-size: 0.95rem;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    transition: all 0.25s ease;
    cursor: pointer;
    text-decoration: none !important;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.1);
}

.sk-btn-glass:hover {
    background: #ffffff;
    color: #03438C !important;
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
}

.sk-hero-wave {
    position: absolute;
    bottom: -1px;
    left: 0;
    width: 100%;
    line-height: 0;
    pointer-events: none;
}

.sk-hero-wave svg {
    width: 100%;
    height: 60px;
    display: block;
}

@media (min-width: 768px) {
    .sk-hero-wave svg {
        height: 75px;
    }
}

/* ══════════════════════════════════════════════════════════════════════
   2. SEARCH & FILTER SECTION ("Find Your Perfect Assessment")
══════════════════════════════════════════════════════════════════════ */
.sk-search-section {
    background-color: #f8fafc;
    padding: 44px 0 36px;
}

.sk-search-header {
    text-align: center;
    margin-bottom: 28px;
}

.sk-search-heading {
    font-size: 1.85rem;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -0.4px;
    margin-bottom: 6px;
}

.sk-search-sub {
    font-size: 0.98rem;
    color: #64748b;
    margin: 0;
}

.sk-floating-card {
    background: #ffffff;
    border-radius: 24px;
    box-shadow: 0 20px 60px -20px rgba(2, 132, 199, 0.25);
    border: 1px solid rgba(224, 242, 254, 0.9);
    padding: 24px;
}

@media (min-width: 768px) {
    .sk-floating-card {
        padding: 32px;
    }
}

.sk-search-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 12px;
}

@media (min-width: 768px) {
    .sk-search-grid {
        grid-template-columns: 2fr 1fr auto;
    }
}

.sk-input-box {
    display: flex;
    align-items: center;
    background: #f8fafc;
    border: 1.5px solid #e2e8f0;
    border-radius: 16px;
    padding: 0 16px;
    transition: all 0.2s ease;
}

.sk-input-box:focus-within {
    background: #ffffff;
    border-color: #0ea5e9;
    box-shadow: 0 0 0 4px rgba(14, 165, 233, 0.15);
}

.sk-input-box i.fa-search {
    color: #0284c7;
    font-size: 1rem;
    margin-right: 12px;
    flex-shrink: 0;
}

.sk-input-field {
    flex: 1;
    background: transparent;
    border: none;
    outline: none;
    padding: 14px 0;
    font-size: 0.95rem;
    color: #1e293b;
}

.sk-input-field::placeholder {
    color: #94a3b8;
}

.sk-clear-icon {
    border: none;
    background: transparent;
    color: #94a3b8;
    cursor: pointer;
    padding: 4px;
    font-size: 1rem;
    transition: color 0.2s;
}

.sk-clear-icon:hover {
    color: #475569;
}

/* Category dropdown */
.sk-dropdown-wrap {
    position: relative;
}

.sk-dropdown-btn {
    width: 100%;
    height: 100%;
    min-height: 50px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #f8fafc;
    border: 1.5px solid #e2e8f0;
    border-radius: 16px;
    padding: 0 18px;
    cursor: pointer;
    font-size: 0.95rem;
    color: #334155;
    transition: all 0.2s;
    text-align: left;
}

.sk-dropdown-btn:hover {
    background: #ffffff;
    border-color: #cbd5e1;
}

.sk-dropdown-menu {
    position: absolute;
    top: calc(100% + 8px);
    left: 0;
    right: 0;
    background: #ffffff;
    border: 1.5px solid #e2e8f0;
    border-radius: 18px;
    box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.15);
    z-index: 50;
    max-height: 280px;
    overflow: hidden;
    display: none;
    flex-direction: column;
}

.sk-dropdown-search-wrap {
    padding: 10px;
    border-bottom: 1px solid #f1f5f9;
}

.sk-dropdown-search-input {
    width: 100%;
    padding: 8px 12px;
    font-size: 0.88rem;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    outline: none;
}

.sk-dropdown-search-input:focus {
    border-color: #0ea5e9;
    background: #ffffff;
}

.sk-dropdown-options {
    overflow-y: auto;
    flex: 1;
}

.sk-dropdown-options::-webkit-scrollbar {
    width: 5px;
}
.sk-dropdown-options::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}

.sk-cat-item {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 16px;
    border: none;
    background: transparent;
    text-align: left;
    font-size: 0.9rem;
    color: #334155;
    cursor: pointer;
    transition: all 0.15s;
}

.sk-cat-item:hover {
    background: #f0f9ff;
    color: #0284c7;
}

.sk-cat-count {
    font-size: 0.75rem;
    background: #f1f5f9;
    color: #64748b;
    padding: 2px 8px;
    border-radius: 9999px;
    font-weight: 600;
}

/* Action buttons */
.sk-actions-row {
    display: flex;
    gap: 8px;
}

.sk-btn-search {
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 13px 24px;
    background: linear-gradient(135deg, #0284c7 0%, #06b6d4 100%);
    color: #ffffff;
    font-weight: 700;
    font-size: 0.95rem;
    border: none;
    border-radius: 16px;
    cursor: pointer;
    box-shadow: 0 8px 20px -4px rgba(2, 132, 199, 0.35);
    transition: all 0.2s ease;
    white-space: nowrap;
}

.sk-btn-search:hover {
    background: linear-gradient(135deg, #0369a1 0%, #0891b2 100%);
    transform: translateY(-1px);
    box-shadow: 0 10px 24px -4px rgba(2, 132, 199, 0.45);
}

.sk-btn-clear {
    display: none;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 13px 18px;
    background: #f1f5f9;
    color: #475569;
    font-weight: 600;
    font-size: 0.92rem;
    border: none;
    border-radius: 16px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.sk-btn-clear:hover {
    background: #e2e8f0;
    color: #1e293b;
}

/* Popular skills row */
.sk-popular-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 20px;
}

.sk-popular-label {
    font-size: 0.88rem;
    font-weight: 600;
    color: #64748b;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-right: 4px;
}

.sk-popular-pill {
    background: #ecfeff;
    color: #0891b2;
    border: 1px solid #cffafe;
    border-radius: 9999px;
    padding: 5px 14px;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.sk-popular-pill:hover {
    background: #06b6d4;
    color: #ffffff;
    border-color: #06b6d4;
    transform: translateY(-1px);
}

/* ══════════════════════════════════════════════════════════════════════
   3. RESULTS & CARDS SECTION (Grid & List View Modes)
══════════════════════════════════════════════════════════════════════ */
.sk-results-section {
    background: #ffffff;
    padding: 44px 0 60px;
}

.sk-results-header {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-bottom: 28px;
}

@media (min-width: 576px) {
    .sk-results-header {
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
    }
}

.sk-results-count-title {
    font-size: 1.45rem;
    font-weight: 800;
    color: #0f172a;
    margin: 0;
}

.sk-filter-status {
    font-size: 0.88rem;
    color: #64748b;
    margin-top: 4px;
    display: none;
}

.sk-view-toggle {
    display: inline-flex;
    background: #f1f5f9;
    padding: 4px;
    border-radius: 12px;
    align-self: flex-start;
}

.sk-view-btn {
    border: none;
    background: transparent;
    padding: 7px 13px;
    border-radius: 8px;
    color: #64748b;
    font-size: 0.95rem;
    cursor: pointer;
    transition: all 0.15s ease;
}

.sk-view-btn.active {
    background: #ffffff;
    color: #0284c7;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
}

/* ─── Grid View Mode (Default) ─── */
.sk-cards-container.is-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 22px;
}

@media (min-width: 640px) {
    .sk-cards-container.is-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (min-width: 992px) {
    .sk-cards-container.is-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

.sk-cards-container.is-grid .sk-card {
    background: #ffffff;
    border: 1.5px solid #e2e8f0;
    border-radius: 20px;
    padding: 24px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.25s ease, border-color 0.2s ease;
    cursor: pointer;
}

.sk-cards-container.is-grid .sk-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 16px 36px -8px rgba(2, 132, 199, 0.12);
    border-color: #7dd3fc;
}

.sk-cards-container.is-grid .sk-card-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 14px;
}

.sk-cards-container.is-grid .sk-card-title {
    font-size: 1.15rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.35;
    margin-bottom: 8px;
    transition: color 0.2s;
}

.sk-cards-container.is-grid .sk-card:hover .sk-card-title {
    color: #0284c7;
}

.sk-cards-container.is-grid .sk-card-desc {
    font-size: 0.88rem;
    color: #64748b;
    line-height: 1.55;
    margin-bottom: 16px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.sk-cards-container.is-grid .sk-card-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    font-size: 0.82rem;
    color: #64748b;
    margin-bottom: 20px;
}

.sk-cards-container.is-grid .sk-card-footer {
    margin-top: auto;
    padding-top: 18px;
    border-top: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

/* ─── List View Mode ─── */
.sk-cards-container.is-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.sk-cards-container.is-list .sk-card {
    background: #ffffff;
    border: 1.5px solid #e2e8f0;
    border-radius: 20px;
    padding: 20px 24px;
    display: grid;
    grid-template-columns: 1fr;
    gap: 16px;
    align-items: center;
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    cursor: pointer;
}

@media (min-width: 992px) {
    .sk-cards-container.is-list .sk-card {
        grid-template-columns: 1fr auto;
    }
}

.sk-cards-container.is-list .sk-card:hover {
    box-shadow: 0 12px 28px -6px rgba(2, 132, 199, 0.10);
    border-color: #7dd3fc;
}

.sk-cards-container.is-list .sk-card-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 8px;
}

.sk-cards-container.is-list .sk-card-title {
    font-size: 1.15rem;
    font-weight: 800;
    color: #0f172a;
    margin-bottom: 4px;
}

.sk-cards-container.is-list .sk-card-desc {
    font-size: 0.88rem;
    color: #64748b;
    margin-bottom: 12px;
    max-width: 700px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.sk-cards-container.is-list .sk-card-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    font-size: 0.82rem;
    color: #64748b;
}

.sk-cards-container.is-list .sk-card-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
}

/* ─── Badge Utilities ─── */
.sk-badge-free {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    background: #d1fae5;
    color: #065f46;
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    border-radius: 9999px;
}

.sk-badge-pro {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    background: #fef3c7;
    color: #92400e;
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    border-radius: 9999px;
}

.sk-badge-diff-easy {
    background: #ecfdf5;
    color: #047857;
    font-size: 0.75rem;
    font-weight: 600;
    padding: 3px 9px;
    border-radius: 6px;
    text-transform: capitalize;
}

.sk-badge-diff-medium {
    background: #fffbeb;
    color: #b45309;
    font-size: 0.75rem;
    font-weight: 600;
    padding: 3px 9px;
    border-radius: 6px;
    text-transform: capitalize;
}

.sk-badge-diff-hard {
    background: #fff1f2;
    color: #be123c;
    font-size: 0.75rem;
    font-weight: 600;
    padding: 3px 9px;
    border-radius: 6px;
    text-transform: capitalize;
}

.sk-badge-cat {
    background: #f1f5f9;
    color: #475569;
    font-size: 0.75rem;
    font-weight: 600;
    padding: 3px 9px;
    border-radius: 6px;
}

.sk-badge-verified {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    background: #e0f2fe;
    color: #0369a1;
    font-size: 0.72rem;
    font-weight: 800;
    border-radius: 9999px;
}

.sk-meta-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.sk-meta-item i {
    color: #0284c7;
}

/* Card CTA Buttons */
.sk-btn-card-start {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 9px 20px;
    background: linear-gradient(135deg, #0284c7 0%, #06b6d4 100%);
    color: #ffffff !important;
    font-weight: 700;
    font-size: 0.88rem;
    border: none;
    border-radius: 12px;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);
    transition: all 0.2s;
    text-decoration: none !important;
    white-space: nowrap;
}

.sk-btn-card-start:hover {
    background: linear-gradient(135deg, #0369a1 0%, #0891b2 100%);
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(2, 132, 199, 0.35);
}

.sk-btn-card-retake {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 8px 18px;
    background: #ffffff;
    color: #047857 !important;
    border: 1.5px solid #10b981;
    font-weight: 700;
    font-size: 0.88rem;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none !important;
    white-space: nowrap;
}

.sk-btn-card-retake:hover {
    background: #ecfdf5;
}

.sk-btn-card-pro {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 9px 18px;
    background: linear-gradient(135deg, #d97706, #f59e0b);
    color: #ffffff !important;
    font-weight: 700;
    font-size: 0.88rem;
    border: none;
    border-radius: 12px;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.25);
    text-decoration: none !important;
    white-space: nowrap;
}

.sk-btn-card-pro:hover {
    background: linear-gradient(135deg, #b45309, #d97706);
}

/* ══════════════════════════════════════════════════════════════════════
   4. TRUST SECTION
══════════════════════════════════════════════════════════════════════ */
.sk-trust-wrap {
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    padding: 48px 0;
}

.sk-trust-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 20px;
}

@media (min-width: 768px) {
    .sk-trust-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

.sk-trust-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 24px;
    display: flex;
    gap: 16px;
    align-items: flex-start;
}

.sk-trust-icon {
    width: 46px;
    height: 46px;
    border-radius: 14px;
    background: #f0f9ff;
    color: #0284c7;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
}

.sk-trust-title {
    font-size: 1rem;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 4px;
}

.sk-trust-desc {
    font-size: 0.88rem;
    color: #64748b;
    line-height: 1.5;
    margin: 0;
}

/* Empty State */
.sk-empty-card {
    background: #ffffff;
    border: 1.5px dashed #cbd5e1;
    border-radius: 20px;
    padding: 48px 24px;
    text-align: center;
}

/* ─── Theme Switcher Controls in Skills Page ─── */
.sk-theme-switch-bar {
    display: inline-flex;
    align-items: center;
    background: #f1f5f9;
    padding: 4px;
    border-radius: 12px;
    gap: 3px;
    border: 1px solid #e2e8f0;
}
.sk-theme-btn {
    border: none;
    background: transparent;
    padding: 6px 11px;
    border-radius: 8px;
    color: #64748b;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
    user-select: none;
}
.sk-theme-btn .dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    display: inline-block;
    flex-shrink: 0;
}
.sk-theme-btn:hover {
    background: rgba(255, 255, 255, 0.7);
    color: #0f172a;
}
.sk-theme-btn.active {
    background: #ffffff;
    color: #0f172a;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
}

/* ─── Smooth Theme Transitions ─── */
.sk-page, .sk-search-section, .sk-results-section, .sk-floating-card,
.sk-card, .sk-input-box, .sk-dropdown-btn, .sk-dropdown-menu,
.sk-trust-wrap, .sk-trust-card, .sk-empty-card, .modal-content, .modal-body,
.sk-view-toggle, .sk-theme-switch-bar {
    transition: background-color 0.3s ease, border-color 0.3s ease, color 0.3s ease, box-shadow 0.3s ease;
}

/* ══════════════════════════════════════════════════════════════════════
   DARK THEME OVERRIDES FOR SKILLS.PHP
══════════════════════════════════════════════════════════════════════ */
:root[data-theme="dark"],
[data-theme="dark"],
body.dark-theme,
body[data-theme="dark"] {
    --sk-bg: #0b1120;
    --sk-surface: #162032;
    --sk-surface-2: #0f172a;
    --sk-border: #1e293b;
    --sk-border-focus: #38bdf8;
    --sk-text-primary: #f8fafc;
    --sk-text-secondary: #94a3b8;
    --sk-text-muted: #64748b;
}

[data-theme="dark"] .sk-page,
body.dark-theme .sk-page {
    background-color: #0b1120 !important;
    color: #f8fafc !important;
}

/* Hero Section */
[data-theme="dark"] .sk-hero-wrap,
body.dark-theme .sk-hero-wrap {
    background: linear-gradient(135deg, #020b18 0%, #08172e 50%, #0a2540 100%) !important;
}
[data-theme="dark"] .sk-hero-wave svg path,
body.dark-theme .sk-hero-wave svg path {
    fill: #0b1120 !important;
}

/* Search & Filter Section */
[data-theme="dark"] .sk-search-section,
body.dark-theme .sk-search-section {
    background-color: #0b1120 !important;
}
[data-theme="dark"] .sk-search-heading,
body.dark-theme .sk-search-heading {
    color: #f8fafc !important;
}
[data-theme="dark"] .sk-search-sub,
body.dark-theme .sk-search-sub {
    color: #94a3b8 !important;
}
[data-theme="dark"] .sk-floating-card,
body.dark-theme .sk-floating-card {
    background: #162032 !important;
    border-color: #1e293b !important;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5) !important;
}
[data-theme="dark"] .sk-input-box,
body.dark-theme .sk-input-box {
    background: #0f172a !important;
    border-color: #334155 !important;
}
[data-theme="dark"] .sk-input-box:focus-within,
body.dark-theme .sk-input-box:focus-within {
    background: #0b1120 !important;
    border-color: #38bdf8 !important;
    box-shadow: 0 0 0 4px rgba(56, 189, 248, 0.2) !important;
}
[data-theme="dark"] .sk-input-field,
body.dark-theme .sk-input-field {
    color: #f8fafc !important;
}
[data-theme="dark"] .sk-input-field::placeholder,
body.dark-theme .sk-input-field::placeholder {
    color: #64748b !important;
}

/* Dropdown Menu */
[data-theme="dark"] .sk-dropdown-btn,
body.dark-theme .sk-dropdown-btn {
    background: #0f172a !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}
[data-theme="dark"] .sk-dropdown-btn:hover,
body.dark-theme .sk-dropdown-btn:hover {
    background: #1e293b !important;
    border-color: #475569 !important;
}
[data-theme="dark"] #selectedCategoryText,
body.dark-theme #selectedCategoryText {
    color: #cbd5e1 !important;
}
[data-theme="dark"] .sk-dropdown-menu,
body.dark-theme .sk-dropdown-menu {
    background: #162032 !important;
    border-color: #334155 !important;
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.6) !important;
}
[data-theme="dark"] .sk-dropdown-search-wrap,
body.dark-theme .sk-dropdown-search-wrap {
    border-color: #1e293b !important;
}
[data-theme="dark"] .sk-dropdown-search-input,
body.dark-theme .sk-dropdown-search-input {
    background: #0f172a !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}
[data-theme="dark"] .sk-cat-item,
body.dark-theme .sk-cat-item {
    color: #cbd5e1 !important;
}
[data-theme="dark"] .sk-cat-item:hover,
body.dark-theme .sk-cat-item:hover {
    background: rgba(56, 189, 248, 0.15) !important;
    color: #38bdf8 !important;
}
[data-theme="dark"] .sk-cat-count,
body.dark-theme .sk-cat-count {
    background: #0f172a !important;
    color: #94a3b8 !important;
}
[data-theme="dark"] .sk-btn-clear,
body.dark-theme .sk-btn-clear {
    background: #1e293b !important;
    color: #cbd5e1 !important;
}
[data-theme="dark"] .sk-btn-clear:hover,
body.dark-theme .sk-btn-clear:hover {
    background: #334155 !important;
    color: #f8fafc !important;
}

/* Popular Skills */
[data-theme="dark"] .sk-popular-label,
body.dark-theme .sk-popular-label {
    color: #94a3b8 !important;
}
[data-theme="dark"] .sk-popular-pill,
body.dark-theme .sk-popular-pill {
    background: rgba(6, 182, 212, 0.12) !important;
    color: #38bdf8 !important;
    border-color: rgba(56, 189, 248, 0.25) !important;
}
[data-theme="dark"] .sk-popular-pill:hover,
body.dark-theme .sk-popular-pill:hover {
    background: #0284c7 !important;
    color: #ffffff !important;
    border-color: #0284c7 !important;
}

/* Results & Cards */
[data-theme="dark"] .sk-results-section,
body.dark-theme .sk-results-section {
    background: #0b1120 !important;
}
[data-theme="dark"] .sk-results-count-title,
body.dark-theme .sk-results-count-title {
    color: #f8fafc !important;
}
[data-theme="dark"] .sk-filter-status,
body.dark-theme .sk-filter-status {
    color: #94a3b8 !important;
}

/* Mode & Theme Toggles */
[data-theme="dark"] .sk-view-toggle,
body.dark-theme .sk-view-toggle {
    background: #162032 !important;
    border: 1px solid #1e293b !important;
}
[data-theme="dark"] .sk-view-btn,
body.dark-theme .sk-view-btn {
    color: #94a3b8 !important;
}
[data-theme="dark"] .sk-view-btn.active,
body.dark-theme .sk-view-btn.active {
    background: #0f172a !important;
    color: #38bdf8 !important;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.35) !important;
}
[data-theme="dark"] .sk-theme-switch-bar,
body.dark-theme .sk-theme-switch-bar {
    background: #162032 !important;
    border: 1px solid #1e293b !important;
}
[data-theme="dark"] .sk-theme-btn,
body.dark-theme .sk-theme-btn {
    color: #94a3b8 !important;
}
[data-theme="dark"] .sk-theme-btn:hover,
body.dark-theme .sk-theme-btn:hover {
    background: rgba(255, 255, 255, 0.08) !important;
    color: #f8fafc !important;
}
[data-theme="dark"] .sk-theme-btn.active,
body.dark-theme .sk-theme-btn.active {
    background: #0f172a !important;
    color: #f8fafc !important;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.4) !important;
}

/* Assessment Cards */
[data-theme="dark"] .sk-card,
body.dark-theme .sk-card {
    background: #162032 !important;
    border-color: #1e293b !important;
}
[data-theme="dark"] .sk-card:hover,
body.dark-theme .sk-card:hover {
    border-color: rgba(56, 189, 248, 0.45) !important;
    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.55), 0 0 20px rgba(56, 189, 248, 0.12) !important;
}
[data-theme="dark"] .sk-card-title,
body.dark-theme .sk-card-title {
    color: #f8fafc !important;
}
[data-theme="dark"] .sk-card:hover .sk-card-title,
body.dark-theme .sk-card:hover .sk-card-title {
    color: #38bdf8 !important;
}
[data-theme="dark"] .sk-card-desc,
body.dark-theme .sk-card-desc {
    color: #94a3b8 !important;
}
[data-theme="dark"] .sk-card-meta,
body.dark-theme .sk-card-meta {
    color: #94a3b8 !important;
}
[data-theme="dark"] .sk-card-footer,
body.dark-theme .sk-card-footer {
    border-top-color: #1e293b !important;
}
[data-theme="dark"] .sk-badge-cat,
body.dark-theme .sk-badge-cat {
    background: #0f172a !important;
    color: #cbd5e1 !important;
    border: 1px solid #334155 !important;
}
[data-theme="dark"] .sk-badge-verified,
body.dark-theme .sk-badge-verified {
    background: rgba(2, 132, 199, 0.25) !important;
    color: #38bdf8 !important;
}
[data-theme="dark"] .sk-btn-card-retake,
body.dark-theme .sk-btn-card-retake {
    background: #0f172a !important;
    color: #34d399 !important;
    border-color: #10b981 !important;
}
[data-theme="dark"] .sk-btn-card-retake:hover,
body.dark-theme .sk-btn-card-retake:hover {
    background: rgba(16, 185, 129, 0.18) !important;
}

/* Empty State Card */
[data-theme="dark"] .sk-empty-card,
body.dark-theme .sk-empty-card {
    background: #162032 !important;
    border-color: #334155 !important;
}
[data-theme="dark"] .sk-empty-card h4,
body.dark-theme .sk-empty-card h4 {
    color: #f8fafc !important;
}
[data-theme="dark"] .sk-empty-card p,
body.dark-theme .sk-empty-card p {
    color: #94a3b8 !important;
}

/* Trust Section */
[data-theme="dark"] .sk-trust-wrap,
body.dark-theme .sk-trust-wrap {
    background: #080f1e !important;
    border-top-color: #1e293b !important;
}
[data-theme="dark"] .sk-trust-card,
body.dark-theme .sk-trust-card {
    background: #162032 !important;
    border-color: #1e293b !important;
}
[data-theme="dark"] .sk-trust-title,
body.dark-theme .sk-trust-title {
    color: #f8fafc !important;
}
[data-theme="dark"] .sk-trust-desc,
body.dark-theme .sk-trust-desc {
    color: #94a3b8 !important;
}
[data-theme="dark"] .sk-trust-icon,
body.dark-theme .sk-trust-icon {
    background: #0f172a !important;
    color: #38bdf8 !important;
}

/* Modals */
[data-theme="dark"] .modal-content,
body.dark-theme .modal-content {
    background: #162032 !important;
    border: 1px solid #334155 !important;
    color: #f8fafc !important;
}
[data-theme="dark"] .modal-header,
body.dark-theme .modal-header {
    background: linear-gradient(135deg, #030d1d 0%, #0c1a30 100%) !important;
    border-bottom: 1px solid #334155 !important;
}
[data-theme="dark"] .modal-body,
body.dark-theme .modal-body {
    background: #0b1120 !important;
}
[data-theme="dark"] .modal-footer,
body.dark-theme .modal-footer {
    background: #162032 !important;
    border-top: 1px solid #334155 !important;
}
[data-theme="dark"] .modal-body .table,
body.dark-theme .modal-body .table {
    color: #f8fafc !important;
}
[data-theme="dark"] .modal-body .table thead,
body.dark-theme .modal-body .table thead {
    background: #0f172a !important;
    color: #94a3b8 !important;
}
[data-theme="dark"] .modal-body .table th,
[data-theme="dark"] .modal-body .table td,
body.dark-theme .modal-body .table th,
body.dark-theme .modal-body .table td {
    border-color: #1e293b !important;
    color: #f1f5f9 !important;
}
[data-theme="dark"] .modal-body .table-hover tbody tr:hover,
body.dark-theme .modal-body .table-hover tbody tr:hover {
    background: rgba(255, 255, 255, 0.05) !important;
}
[data-theme="dark"] .modal-body .text-dark,
body.dark-theme .modal-body .text-dark {
    color: #f8fafc !important;
}
[data-theme="dark"] .modal-body div[style*="background: #ffffff"],
body.dark-theme .modal-body div[style*="background: #ffffff"],
[data-theme="dark"] .modal-body div[style*="background:#ffffff"],
body.dark-theme .modal-body div[style*="background:#ffffff"] {
    background: #162032 !important;
    border-color: #334155 !important;
}

/* ══════════════════════════════════════════════════════════════════════
   OCEAN THEME OVERRIDES FOR SKILLS.PHP
══════════════════════════════════════════════════════════════════════ */
[data-theme="ocean"] .sk-hero-wrap {
    background: linear-gradient(135deg, #042f2e 0%, #0d9488 50%, #0891b2 100%) !important;
}
[data-theme="ocean"] .sk-btn-search,
[data-theme="ocean"] .sk-btn-card-start {
    background: linear-gradient(135deg, #0d9488 0%, #06b6d4 100%) !important;
    box-shadow: 0 8px 20px -4px rgba(13, 148, 136, 0.35) !important;
}
[data-theme="ocean"] .sk-btn-search:hover,
[data-theme="ocean"] .sk-btn-card-start:hover {
    background: linear-gradient(135deg, #0f766e 0%, #0891b2 100%) !important;
}
[data-theme="ocean"] .sk-card:hover {
    border-color: #5eead4 !important;
}
[data-theme="ocean"] .sk-card:hover .sk-card-title {
    color: #0d9488 !important;
}
[data-theme="ocean"] .sk-popular-pill {
    background: #f0fdfa !important;
    color: #0d9488 !important;
    border-color: #ccfbf1 !important;
}
[data-theme="ocean"] .sk-popular-pill:hover {
    background: #0d9488 !important;
    color: #ffffff !important;
}
[data-theme="ocean"] .sk-meta-item i {
    color: #0d9488 !important;
}

/* ══════════════════════════════════════════════════════════════════════
   SUNSET THEME OVERRIDES FOR SKILLS.PHP
══════════════════════════════════════════════════════════════════════ */
[data-theme="sunset"] .sk-hero-wrap {
    background: linear-gradient(135deg, #450a0a 0%, #b91c1c 50%, #ea580c 100%) !important;
}
[data-theme="sunset"] .sk-btn-search,
[data-theme="sunset"] .sk-btn-card-start {
    background: linear-gradient(135deg, #dc2626 0%, #f97316 100%) !important;
    box-shadow: 0 8px 20px -4px rgba(220, 38, 38, 0.35) !important;
}
[data-theme="sunset"] .sk-btn-search:hover,
[data-theme="sunset"] .sk-btn-card-start:hover {
    background: linear-gradient(135deg, #991b1b 0%, #ea580c 100%) !important;
}
[data-theme="sunset"] .sk-card:hover {
    border-color: #fdba74 !important;
}
[data-theme="sunset"] .sk-card:hover .sk-card-title {
    color: #ea580c !important;
}
[data-theme="sunset"] .sk-popular-pill {
    background: #fff7ed !important;
    color: #ea580c !important;
    border-color: #ffedd5 !important;
}
[data-theme="sunset"] .sk-popular-pill:hover {
    background: #ea580c !important;
    color: #ffffff !important;
}
[data-theme="sunset"] .sk-meta-item i {
    color: #ea580c !important;
}
</style>

<div class="sk-page">

    <!-- ══════════════════════════════════════════════════════════════════════
         1. HERO SECTION (Exact Skill.Jobs Visuals)
    ══════════════════════════════════════════════════════════════════════ -->
    <div class="sk-hero-wrap">
        <div class="sk-hero-dots"></div>
        <div class="sk-hero-glow-1"></div>
        <div class="sk-hero-glow-2"></div>

        <div class="sk-container">
            <div class="sk-hero-flex">
                <div class="sk-hero-text">
                    <h1 class="sk-hero-title">
                        Discover Your <span class="sk-hero-highlight">S</span>kills<br/>Unlock Your Potential
                    </h1>
                    <p class="sk-hero-subtitle">
                        Take our comprehensive skill assessments to identify your strengths, track your progress, and accelerate your career growth.
                    </p>
                </div>

                <div class="sk-hero-actions">
                    <?php if (!empty($user_verified)): ?>
                        <button type="button" class="sk-btn-glass" data-toggle="modal" data-target="#myResultsModal">
                            <i class="fas fa-award text-warning"></i>
                            <span>View My Results (<?php echo $total_user_verified; ?>)</span>
                        </button>
                    <?php endif; ?>
                    <button type="button" class="sk-btn-glass" data-toggle="modal" data-target="#leaderboardModal">
                        <i class="fas fa-trophy text-warning"></i>
                        <span>View Leaderboards</span>
                    </button>
                    <button type="button" class="sk-btn-glass" id="skThemeToggleBtn" onclick="toggleSkillsTheme()" title="Toggle Dark / Light Theme">
                        <i class="fas fa-moon" id="skThemeIcon"></i>
                        <span id="skThemeText">Dark Mode</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Bottom Curved SVG Wave Divider to #f8fafc -->
        <div class="sk-hero-wave">
            <svg viewBox="0 0 1440 80" preserveAspectRatio="none" aria-hidden="true">
                <path d="M0,40 C360,90 1080,-10 1440,40 L1440,80 L0,80 Z" fill="#f8fafc"></path>
            </svg>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         2. SEARCH & FILTERS ("Find Your Perfect Assessment")
    ══════════════════════════════════════════════════════════════════════ -->
    <div class="sk-search-section">
        <div class="sk-container">
            <div class="sk-search-header">
                <h2 class="sk-search-heading">Find Your Perfect Assessment</h2>
                <p class="sk-search-sub">Search from our extensive collection of skill tests</p>
            </div>

            <div class="sk-floating-card">
                <div class="sk-search-grid">
                    <!-- Search Input Box -->
                    <div class="sk-input-box">
                        <i class="fas fa-search"></i>
                        <input type="text" 
                               id="skillSearchInput" 
                               class="sk-input-field"
                               placeholder="Search skills... (e.g., Python, React, Data Analysis)" 
                               autocomplete="off">
                        <button type="button" id="clearSearchBtn" class="sk-clear-icon" style="display: none;" title="Clear search">
                            <i class="fas fa-times-circle"></i>
                        </button>
                    </div>

                    <!-- Category Selector Dropdown -->
                    <div class="sk-dropdown-wrap" id="categoryDropdownWrap">
                        <button type="button" id="categoryDropdownBtn" class="sk-dropdown-btn">
                            <span id="selectedCategoryText" style="color: #64748b;">Select Category</span>
                            <i class="fas fa-chevron-down text-muted" id="catChevron" style="font-size: 0.8rem; transition: transform 0.2s;"></i>
                        </button>

                        <div id="categoryMenu" class="sk-dropdown-menu">
                            <div class="sk-dropdown-search-wrap">
                                <input type="text" id="catSearchInput" placeholder="Search categories..." class="sk-dropdown-search-input">
                            </div>
                            <div class="sk-dropdown-options" id="catListOptions">
                                <button type="button" class="sk-cat-item" data-cat-id="all">
                                    <span style="font-weight: 600;">All Categories</span>
                                    <span class="sk-cat-count"><?php echo $total_assessments; ?></span>
                                </button>
                                <?php foreach ($all_categories as $cat): ?>
                                    <button type="button" class="sk-cat-item" data-cat-id="<?php echo $cat['id']; ?>">
                                        <span><?php echo htmlspecialchars($cat['name']); ?></span>
                                        <span class="sk-cat-count"><?php echo $cat['skill_count']; ?></span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="sk-actions-row">
                        <button type="button" id="btnApplySearch" class="sk-btn-search">
                            <i class="fas fa-search"></i>
                            <span>Search</span>
                        </button>
                        <button type="button" id="btnClearAllFilters" class="sk-btn-clear">
                            <i class="fas fa-undo"></i>
                            <span>Clear</span>
                        </button>
                    </div>
                </div>

                <!-- Popular Skills Row -->
                <div class="sk-popular-row">
                    <span class="sk-popular-label">
                        <i class="fas fa-bolt text-warning"></i> Popular Skills:
                    </span>
                    <?php 
                    $popular_tags = ['JavaScript', 'Python', 'React', 'SQL', 'Node.js', 'HTML/CSS', 'Data Analysis', 'PHP', 'Agile'];
                    foreach ($popular_tags as $ptag):
                    ?>
                        <button type="button" class="sk-popular-pill" data-skill="<?php echo $ptag; ?>">
                            <?php echo $ptag; ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         3. ASSESSMENTS RESULTS SECTION (Grid & List View Modes)
    ══════════════════════════════════════════════════════════════════════ -->
    <div class="sk-results-section">
        <div class="sk-container">
            <!-- Results Header & Mode Switcher -->
            <div class="sk-results-header">
                <div>
                    <h3 class="sk-results-count-title">
                        <span id="foundCount"><?php echo $total_assessments; ?></span> Assessments Found
                    </h3>
                    <div class="sk-filter-status" id="filterStatusText"></div>
                </div>

                <div class="d-flex align-items-center flex-wrap" style="gap: 10px;">
                    <!-- Theme Selector Pills -->
                    <div class="sk-theme-switch-bar" title="Select Theme">
                        <button type="button" class="sk-theme-btn" data-theme-name="default" onclick="setTheme('default'); updateSkillsThemeUI('default');" title="Default Theme">
                            <span class="dot" style="background: #4f46e5;"></span>
                            <span class="d-none d-sm-inline">Default</span>
                        </button>
                        <button type="button" class="sk-theme-btn" data-theme-name="ocean" onclick="setTheme('ocean'); updateSkillsThemeUI('ocean');" title="Ocean Theme">
                            <span class="dot" style="background: #0d9488;"></span>
                            <span class="d-none d-sm-inline">Ocean</span>
                        </button>
                        <button type="button" class="sk-theme-btn" data-theme-name="sunset" onclick="setTheme('sunset'); updateSkillsThemeUI('sunset');" title="Sunset Theme">
                            <span class="dot" style="background: #ea580c;"></span>
                            <span class="d-none d-sm-inline">Sunset</span>
                        </button>
                        <button type="button" class="sk-theme-btn" data-theme-name="dark" onclick="setTheme('dark'); updateSkillsThemeUI('dark');" title="Dark Theme">
                            <span class="dot" style="background: #0f172a; border: 1.5px solid #38bdf8;"></span>
                            <span class="d-none d-sm-inline">Dark</span>
                        </button>
                    </div>

                    <!-- View Mode Toggle -->
                    <div class="sk-view-toggle">
                        <button type="button" id="btnModeGrid" class="sk-view-btn active" title="Grid View">
                            <i class="fas fa-th-large"></i>
                        </button>
                        <button type="button" id="btnModeList" class="sk-view-btn" title="List View">
                            <i class="fas fa-bars"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Cards Container -->
            <div id="assessmentsContainer" class="sk-cards-container is-grid">
                <?php if (!empty($assessments)): ?>
                    <?php foreach ($assessments as $item): ?>
                        <div class="sk-card"
                             data-id="<?php echo $item['assessment_id']; ?>"
                             data-skill-name="<?php echo htmlspecialchars(strtolower($item['skill_name'] . ' ' . $item['assessment_title'])); ?>"
                             data-cat-id="<?php echo $item['cat_id']; ?>"
                             data-is-pro="<?php echo $item['is_premium'] ? '1' : '0'; ?>"
                             onclick="handleCardClick(<?php echo $item['assessment_id']; ?>, '<?php echo htmlspecialchars(addslashes($item['skill_name'])); ?>', <?php echo $item['is_premium'] ? 'true' : 'false'; ?>)">
                            
                            <div class="sk-card-main-content">
                                <!-- Badges -->
                                <div class="sk-card-badges">
                                    <?php if ($item['is_premium']): ?>
                                        <span class="sk-badge-pro">
                                            <i class="fas fa-crown" style="font-size: 10px;"></i> Premium
                                        </span>
                                    <?php else: ?>
                                        <span class="sk-badge-free">
                                            <i class="fas fa-check" style="font-size: 10px;"></i> Free
                                        </span>
                                    <?php endif; ?>

                                    <?php 
                                        $diff = $item['difficulty'];
                                        $diffClass = ($diff === 'easy') ? 'sk-badge-diff-easy' : (($diff === 'medium') ? 'sk-badge-diff-medium' : 'sk-badge-diff-hard');
                                    ?>
                                    <span class="<?php echo $diffClass; ?>">
                                        <?php echo ucfirst($diff); ?>
                                    </span>

                                    <span class="sk-badge-cat">
                                        <?php echo htmlspecialchars($item['cat_name']); ?>
                                    </span>

                                    <?php if ($item['is_user_verified']): ?>
                                        <span class="sk-badge-verified">
                                            <i class="fas fa-certificate" style="font-size: 10px;"></i> Verified (<?php echo round($item['user_verification']['score']); ?>%)
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <!-- Title & Description -->
                                <h4 class="sk-card-title"><?php echo htmlspecialchars($item['skill_name']); ?></h4>
                                <p class="sk-card-desc">
                                    <?php echo htmlspecialchars($item['assessment_desc'] ?: $item['skill_desc'] ?: 'Assess and prove your practical competencies.'); ?>
                                </p>

                                <!-- Meta Stats -->
                                <div class="sk-card-meta">
                                    <span class="sk-meta-item">
                                        <i class="far fa-file-alt"></i>
                                        <span><?php echo $item['question_count'] > 0 ? $item['question_count'] : 10; ?> Questions</span>
                                    </span>
                                    <span class="sk-meta-item">
                                        <i class="fas fa-award"></i>
                                        <span>Pass: <?php echo $item['passing_score']; ?>%</span>
                                    </span>
                                    <span class="sk-meta-item">
                                        <i class="far fa-clock"></i>
                                        <span><?php echo $item['duration_minutes']; ?> Min</span>
                                    </span>
                                </div>
                            </div>

                            <!-- Footer CTA -->
                            <div class="sk-card-footer">
                                <div class="sk-meta-item text-muted" style="font-size: 0.78rem;">
                                    <i class="fas fa-user-check"></i>
                                    <span><?php echo $item['verified_count'] > 0 ? $item['verified_count'] . ' certified' : 'Skill Assessment'; ?></span>
                                </div>

                                <div onclick="event.stopPropagation();">
                                    <?php if ($item['is_premium'] && !$is_pro): ?>
                                        <a href="pro.php" class="sk-btn-card-pro">
                                            <i class="fas fa-lock"></i> PRO
                                        </a>
                                    <?php elseif ($item['is_user_verified']): ?>
                                        <button type="button" onclick="startAssessment(<?php echo $item['assessment_id']; ?>, '<?php echo htmlspecialchars(addslashes($item['skill_name'])); ?>')" class="sk-btn-card-retake">
                                            <i class="fas fa-redo"></i> Retake Test
                                        </button>
                                    <?php else: ?>
                                        <button type="button" onclick="startAssessment(<?php echo $item['assessment_id']; ?>, '<?php echo htmlspecialchars(addslashes($item['skill_name'])); ?>')" class="sk-btn-card-start">
                                            <i class="fas fa-play" style="font-size: 11px;"></i> Start Test
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>

                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Empty Search Box -->
            <div id="noResultsBox" class="sk-empty-card" style="display: none; margin-top: 20px;">
                <div style="font-size: 2.2rem; color: #0284c7; margin-bottom: 12px;">
                    <i class="fas fa-search"></i>
                </div>
                <h4 style="font-weight: 800; color: #0f172a; margin-bottom: 6px;">No skill assessments found</h4>
                <p style="color: #64748b; font-size: 0.92rem; margin-bottom: 18px;">We couldn't find any skill assessments matching your filters. Try clearing your search or picking another category.</p>
                <button type="button" onclick="resetAllFilters()" class="sk-btn-search" style="margin: 0 auto;">
                    <i class="fas fa-undo"></i> Reset All Filters
                </button>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         4. TRUST & BENEFIT HIGHLIGHTS
    ══════════════════════════════════════════════════════════════════════ -->
    <div class="sk-trust-wrap">
        <div class="sk-container">
            <div class="sk-trust-grid">
                <div class="sk-trust-card">
                    <div class="sk-trust-icon">
                        <i class="fas fa-medal"></i>
                    </div>
                    <div>
                        <div class="sk-trust-title">Prove Competency</div>
                        <p class="sk-trust-desc">Showcase authentic, evaluated knowledge with verified skill badges visible on your profile.</p>
                    </div>
                </div>

                <div class="sk-trust-card">
                    <div class="sk-trust-icon" style="color: #06b6d4; background: #ecfeff;">
                        <i class="fas fa-arrow-trend-up"></i>
                    </div>
                    <div>
                        <div class="sk-trust-title">Higher Shortlisting</div>
                        <p class="sk-trust-desc">Top recruiters filter candidates by verified skills, boosting interview shortlist rates.</p>
                    </div>
                </div>

                <div class="sk-trust-card">
                    <div class="sk-trust-icon" style="color: #10b981; background: #ecfdf5;">
                        <i class="fas fa-bolt"></i>
                    </div>
                    <div>
                        <div class="sk-trust-title">100% Optional</div>
                        <p class="sk-trust-desc">Take assessments whenever you are ready. Retake anytime to improve your proficiency score.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- ══════════════════════════════════════════════════════════════════════
     5. MODALS (View My Results & Leaderboards)
══════════════════════════════════════════════════════════════════════ -->

<!-- My Results Modal -->
<div class="modal fade" id="myResultsModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content border-0" style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px rgba(0,0,0,0.2);">
            <div class="modal-header text-white" style="background: linear-gradient(135deg, #022859 0%, #03438C 100%); padding: 20px 24px;">
                <div>
                    <h5 class="modal-title font-weight-bold m-0" style="font-size: 1.25rem;">
                        <i class="fas fa-award text-warning mr-2"></i> My Verified Badges
                    </h5>
                    <small style="color: #e0f2fe;">Skills you have demonstrated and verified on NovaHire</small>
                </div>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" style="opacity: 0.9;">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-4" style="background: #f8fafc; max-height: 65vh; overflow-y: auto;">
                <?php if (!empty($user_verified)): ?>
                    <div class="row">
                        <?php foreach ($user_verified as $uv): ?>
                            <div class="col-md-6 mb-3">
                                <div style="background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 16px; padding: 18px;">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #64748b; display: block;">
                                                <?php echo htmlspecialchars($uv['category_name']); ?>
                                            </span>
                                            <h6 class="font-weight-bold m-0" style="color: #0f172a; font-size: 1rem;">
                                                <?php echo htmlspecialchars($uv['skill_name']); ?>
                                            </h6>
                                        </div>
                                        <span class="badge badge-success px-2 py-1" style="background: #10b981; font-weight: 700;">
                                            <?php echo htmlspecialchars($uv['skill_level']); ?>
                                        </span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center text-muted pt-2 border-top" style="font-size: 0.8rem;">
                                        <span>Score: <strong class="text-dark"><?php echo round($uv['score']); ?>%</strong></span>
                                        <span><?php echo date('M d, Y', strtotime($uv['verified_at'])); ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-certificate text-muted fa-3x mb-3"></i>
                        <h6 class="font-weight-bold text-dark">No Verified Badges Yet</h6>
                        <p class="text-muted small">Take any assessment above to verify your skills.</p>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer justify-content-between" style="background: #ffffff; padding: 14px 24px;">
                <a href="profile.php" class="btn btn-outline-primary btn-sm font-weight-bold" style="border-radius: 12px;">
                    <i class="fas fa-user-circle mr-1"></i> View on Profile
                </a>
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal" style="border-radius: 12px;">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Leaderboard Modal -->
<div class="modal fade" id="leaderboardModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content border-0" style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px rgba(0,0,0,0.2);">
            <div class="modal-header text-white" style="background: linear-gradient(135deg, #022859 0%, #03438C 100%); padding: 20px 24px;">
                <div>
                    <h5 class="modal-title font-weight-bold m-0" style="font-size: 1.25rem;">
                        <i class="fas fa-trophy text-warning mr-2"></i> Verified Skills Leaderboard
                    </h5>
                    <small style="color: #e0f2fe;">Top candidates certified through NovaHire skill assessments</small>
                </div>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" style="opacity: 0.9;">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-0" style="max-height: 65vh; overflow-y: auto;">
                <?php if (!empty($leaderboard)): ?>
                    <div class="table-responsive m-0">
                        <table class="table table-hover mb-0">
                            <thead style="background: #f8fafc; font-size: 0.8rem; text-transform: uppercase; color: #64748b;">
                                <tr>
                                    <th class="px-4 py-3">Rank</th>
                                    <th class="py-3">Candidate</th>
                                    <th class="py-3">Skill</th>
                                    <th class="py-3">Level</th>
                                    <th class="py-3 text-right px-4">Score</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rank = 1; foreach ($leaderboard as $lb): ?>
                                    <tr>
                                        <td class="px-4 py-3 font-weight-bold" style="color: <?php echo $rank === 1 ? '#d97706' : ($rank === 2 ? '#64748b' : ($rank === 3 ? '#b45309' : '#94a3b8')); ?>;">
                                            <?php if ($rank <= 3): ?>
                                                <i class="fas fa-medal mr-1"></i> #<?php echo $rank; ?>
                                            <?php else: ?>
                                                #<?php echo $rank; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 font-weight-bold text-dark"><?php echo htmlspecialchars($lb['username']); ?></td>
                                        <td class="py-3 text-muted"><?php echo htmlspecialchars($lb['skill_name']); ?></td>
                                        <td class="py-3">
                                            <span class="badge badge-success px-2 py-1" style="background: #10b981; font-weight: 600;">
                                                <?php echo htmlspecialchars($lb['skill_level']); ?>
                                            </span>
                                        </td>
                                        <td class="py-3 text-right px-4 font-weight-bold text-primary"><?php echo round($lb['score']); ?>%</td>
                                    </tr>
                                <?php $rank++; endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-trophy text-muted fa-3x mb-3"></i>
                        <h6 class="font-weight-bold text-dark">No Leaderboard Data Yet</h6>
                        <p class="text-muted small">Be the first to take a test and claim the #1 spot!</p>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer" style="background: #ffffff; padding: 14px 24px;">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal" style="border-radius: 12px;">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     6. INTERACTION SCRIPTS (Search, Filters, View Modes)
══════════════════════════════════════════════════════════════════════ -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    let currentCategory = 'all';
    let currentCategoryName = 'All Categories';
    let searchQuery = '';
    let currentViewMode = localStorage.getItem('skill_jobs_view_mode') || 'grid';

    // Elements
    const searchInput = document.getElementById('skillSearchInput');
    const clearSearchBtn = document.getElementById('clearSearchBtn');
    const btnApplySearch = document.getElementById('btnApplySearch');
    const btnClearAllFilters = document.getElementById('btnClearAllFilters');
    const popularPills = document.querySelectorAll('.sk-popular-pill');

    // Dropdown
    const catDropdownBtn = document.getElementById('categoryDropdownBtn');
    const catMenu = document.getElementById('categoryMenu');
    const catChevron = document.getElementById('catChevron');
    const catSearchInput = document.getElementById('catSearchInput');
    const selectedCatText = document.getElementById('selectedCategoryText');
    const catOptions = document.querySelectorAll('.sk-cat-item');

    // Cards & View Mode
    const container = document.getElementById('assessmentsContainer');
    const cards = document.querySelectorAll('.sk-card');
    const foundCount = document.getElementById('foundCount');
    const filterStatusText = document.getElementById('filterStatusText');
    const noResultsBox = document.getElementById('noResultsBox');
    const btnModeGrid = document.getElementById('btnModeGrid');
    const btnModeList = document.getElementById('btnModeList');

    // 1. Dropdown Open/Close
    if (catDropdownBtn && catMenu) {
        catDropdownBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            const isOpen = catMenu.style.display === 'flex';
            if (isOpen) {
                catMenu.style.display = 'none';
                if (catChevron) catChevron.style.transform = 'rotate(0deg)';
            } else {
                catMenu.style.display = 'flex';
                if (catChevron) catChevron.style.transform = 'rotate(180deg)';
                if (catSearchInput) {
                    setTimeout(() => catSearchInput.focus(), 100);
                }
            }
        });

        document.addEventListener('click', function(e) {
            if (!catMenu.contains(e.target) && !catDropdownBtn.contains(e.target)) {
                catMenu.style.display = 'none';
                if (catChevron) catChevron.style.transform = 'rotate(0deg)';
            }
        });

        if (catSearchInput) {
            catSearchInput.addEventListener('input', function(e) {
                const term = e.target.value.toLowerCase().trim();
                catOptions.forEach(opt => {
                    const text = opt.innerText.toLowerCase();
                    opt.style.display = text.includes(term) ? 'flex' : 'none';
                });
            });
        }

        catOptions.forEach(opt => {
            opt.addEventListener('click', function() {
                const catId = this.getAttribute('data-cat-id');
                const catName = this.querySelector('span').innerText;
                currentCategory = catId;
                currentCategoryName = catName;

                selectedCatText.innerText = (catId === 'all') ? 'Select Category' : catName;
                selectedCatText.style.color = (catId === 'all') ? '#64748b' : '#0f172a';

                catMenu.style.display = 'none';
                if (catChevron) catChevron.style.transform = 'rotate(0deg)';
                applyFilters();
            });
        });
    }

    // 2. Search Input
    if (searchInput) {
        searchInput.addEventListener('input', function(e) {
            searchQuery = e.target.value.trim();
            if (clearSearchBtn) {
                clearSearchBtn.style.display = searchQuery.length > 0 ? 'inline-block' : 'none';
            }
            applyFilters();
        });

        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                applyFilters();
            }
        });
    }

    if (clearSearchBtn) {
        clearSearchBtn.addEventListener('click', function() {
            searchInput.value = '';
            searchQuery = '';
            clearSearchBtn.style.display = 'none';
            applyFilters();
        });
    }

    if (btnApplySearch) {
        btnApplySearch.addEventListener('click', applyFilters);
    }

    // 3. Popular Skills Pill Click
    popularPills.forEach(pill => {
        pill.addEventListener('click', function() {
            const skill = this.getAttribute('data-skill');
            searchInput.value = skill;
            searchQuery = skill;
            if (clearSearchBtn) clearSearchBtn.style.display = 'inline-block';
            applyFilters();
        });
    });

    // 4. View Mode (Grid vs List)
    function applyViewMode(mode) {
        currentViewMode = mode;
        localStorage.setItem('skill_jobs_view_mode', mode);

        if (mode === 'list') {
            container.classList.remove('is-grid');
            container.classList.add('is-list');
            btnModeList.classList.add('active');
            btnModeGrid.classList.remove('active');
        } else {
            container.classList.remove('is-list');
            container.classList.add('is-grid');
            btnModeGrid.classList.add('active');
            btnModeList.classList.remove('active');
        }
    }

    if (btnModeGrid) btnModeGrid.addEventListener('click', () => applyViewMode('grid'));
    if (btnModeList) btnModeList.addEventListener('click', () => applyViewMode('list'));
    applyViewMode(currentViewMode);

    // 5. Filter Engine
    function applyFilters() {
        const query = searchQuery.toLowerCase();
        let visible = 0;

        cards.forEach(card => {
            const skillName = card.getAttribute('data-skill-name') || '';
            const catId = card.getAttribute('data-cat-id');

            const matchesSearch = !query || skillName.includes(query);
            const matchesCat = (currentCategory === 'all') || (catId === currentCategory);

            if (matchesSearch && matchesCat) {
                card.style.display = (currentViewMode === 'list') ? 'grid' : 'flex';
                visible++;
            } else {
                card.style.display = 'none';
            }
        });

        if (foundCount) foundCount.innerText = visible;

        const hasActiveFilters = (query.length > 0 || currentCategory !== 'all');
        if (btnClearAllFilters) {
            btnClearAllFilters.style.display = hasActiveFilters ? 'inline-flex' : 'none';
        }

        if (filterStatusText) {
            if (hasActiveFilters) {
                let text = '';
                if (query) text += `Results for “${searchQuery}”`;
                if (query && currentCategory !== 'all') text += ` in ${currentCategoryName}`;
                else if (currentCategory !== 'all') text += `Category: ${currentCategoryName}`;
                filterStatusText.innerText = text;
                filterStatusText.style.display = 'block';
            } else {
                filterStatusText.style.display = 'none';
            }
        }

        if (noResultsBox) {
            noResultsBox.style.display = (visible === 0) ? 'block' : 'none';
        }
    }

    // 6. Reset Filters
    window.resetAllFilters = function() {
        searchQuery = '';
        if (searchInput) searchInput.value = '';
        if (clearSearchBtn) clearSearchBtn.style.display = 'none';

        currentCategory = 'all';
        currentCategoryName = 'All Categories';
        if (selectedCatText) {
            selectedCatText.innerText = 'Select Category';
            selectedCatText.style.color = '#64748b';
        }

        applyFilters();
    };

    if (btnClearAllFilters) {
        btnClearAllFilters.addEventListener('click', window.resetAllFilters);
    }
});

// Card click & start assessment
function handleCardClick(id, skillName, isPro) {
    startAssessment(id, skillName);
}

function startAssessment(id, skillName) {
    const promptMsg = `Ready to begin the "${skillName}" Skill Assessment?\n\nThe assessment is timed and tests your practical knowledge. Click OK when you are ready to start.`;
    if (confirm(promptMsg)) {
        window.location.href = 'assessment_engine.php?id=' + id;
    }
}

// ══════════════════════════════════════════════════════════════════════
// Theme Synchronization & Switching for Skills Page
// ══════════════════════════════════════════════════════════════════════
function toggleSkillsTheme() {
    const current = document.documentElement.getAttribute('data-theme') || localStorage.getItem('theme') || 'default';
    const next = (current === 'dark') ? 'default' : 'dark';
    if (typeof setTheme === 'function') {
        setTheme(next);
    } else {
        document.documentElement.setAttribute('data-theme', next);
        document.body.setAttribute('data-theme', next);
        if (next === 'dark') document.body.classList.add('dark-theme');
        else document.body.classList.remove('dark-theme');
        localStorage.setItem('theme', next);
        localStorage.setItem('company-theme', next);
    }
    updateSkillsThemeUI(next);
}

function updateSkillsThemeUI(theme) {
    if (!theme) {
        theme = document.documentElement.getAttribute('data-theme') || localStorage.getItem('theme') || 'default';
    }
    const isDark = (theme === 'dark');
    const icon = document.getElementById('skThemeIcon');
    const text = document.getElementById('skThemeText');
    if (icon && text) {
        if (isDark) {
            icon.className = 'fas fa-sun text-warning';
            text.textContent = 'Light Mode';
        } else {
            icon.className = 'fas fa-moon';
            text.textContent = 'Dark Mode';
        }
    }
    document.querySelectorAll('.sk-theme-btn').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-theme-name') === theme);
    });
}

// Hook into existing setTheme from header.php if present
if (typeof window.setTheme === 'function') {
    const _origSetTheme = window.setTheme;
    window.setTheme = function(themeName) {
        _origSetTheme(themeName);
        updateSkillsThemeUI(themeName);
    };
}

// Initialize on page load
(function initSkillsTheme() {
    const saved = document.documentElement.getAttribute('data-theme') || localStorage.getItem('theme') || 'default';
    updateSkillsThemeUI(saved);
})();
</script>
</body>
</html>
