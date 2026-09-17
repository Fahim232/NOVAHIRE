<?php
/**
 * NovaHire AI - CV Generation & Persistence API Endpoint
 * 
 * Handlers:
 * - POST action=generate:        Generate full CV JSON from profile + target job
 * - POST action=enhance_section: Polish / rewrite a specific section with AI
 * - POST action=save:            Persist full customized CV to database
 * - GET/POST action=load:        Retrieve saved CV snapshots or list
 */

header('Content-Type: application/json; charset=utf-8');

// Bootstrap & Session
require_once __DIR__ . '/../includes/bootstrap.php';

if (!isset($_SESSION['id'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized. Please log in as a job seeker.']);
    exit;
}

$user_id = intval($_SESSION['id']);

require_once __DIR__ . '/../admin/dbcon.php';
require_once __DIR__ . '/../ai/config.php';
require_once __DIR__ . '/../ai/engine.php';
require_once __DIR__ . '/../ai/cv_generator.php';

// Ensure table exists
ai_ensure_cv_table($con);

// Support both FormData (standard $_POST) and raw JSON input from fetch
$json_input = file_get_contents('php://input');
if ($json_input) {
    $decoded = json_decode($json_input, true);
    if (is_array($decoded)) {
        $_POST = array_merge($_POST, $decoded);
    }
}

// Determine action
$action = $_POST['action'] ?? $_GET['action'] ?? 'generate';

// ─────────────────────────────────────────────────────────────
// 1. ACTION: GENERATE
// ─────────────────────────────────────────────────────────────
if ($action === 'generate') {
    $job_id = isset($_POST['job_id']) && intval($_POST['job_id']) > 0 ? intval($_POST['job_id']) : null;

    // Fetch user info
    $stmt = mysqli_prepare($con, "SELECT * FROM user_info WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $user_res = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($user_res);
    mysqli_stmt_close($stmt);

    if (!$user) {
        echo json_encode(['ok' => false, 'error' => 'User profile not found.']);
        exit;
    }

    // Fetch target job if selected
    $job = null;
    if ($job_id) {
        $jstmt = mysqli_prepare($con, "SELECT cj.*, c.company_name, c.logo, c.industry 
                                       FROM company_jobs cj 
                                       JOIN companies c ON cj.company_id = c.id 
                                       WHERE cj.id = ? LIMIT 1");
        mysqli_stmt_bind_param($jstmt, "i", $job_id);
        mysqli_stmt_execute($jstmt);
        $jres = mysqli_stmt_get_result($jstmt);
        $job = mysqli_fetch_assoc($jres);
        mysqli_stmt_close($jstmt);
    }

    try {
        $cv_data = ai_generate_cv_content($user, $job);
        echo json_encode([
            'ok'      => true,
            'data'    => $cv_data,
            'message' => 'CV content successfully generated'
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Generation error: ' . $e->getMessage()]);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────
// 2. ACTION: ENHANCE SECTION
// ─────────────────────────────────────────────────────────────
if ($action === 'enhance_section') {
    $section_type  = trim((string)($_POST['section_type'] ?? 'summary'));
    $original_text = trim((string)($_POST['original_text'] ?? ''));
    $target_role   = trim((string)($_POST['target_role'] ?? ''));

    if ($original_text === '') {
        echo json_encode(['ok' => false, 'error' => 'Original text cannot be empty.']);
        exit;
    }

    try {
        $enhanced = ai_enhance_section($section_type, $original_text, ['role' => $target_role]);
        echo json_encode([
            'ok'            => true,
            'enhanced_text' => $enhanced,
            'section_type'  => $section_type
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Enhancement error: ' . $e->getMessage()]);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────
// 3. ACTION: SAVE
// ─────────────────────────────────────────────────────────────
if ($action === 'save') {
    $job_id        = !empty($_POST['job_id']) ? intval($_POST['job_id']) : null;
    $template_name = trim((string)($_POST['template_name'] ?? 'modern'));
    if (!in_array($template_name, ['modern', 'executive', 'minimalist'])) {
        $template_name = 'modern';
    }

    $full_name = trim((string)($_POST['full_name'] ?? ''));
    if ($full_name === '') {
        echo json_encode(['ok' => false, 'error' => 'Candidate full name is required.']);
        exit;
    }

    $headline  = trim((string)($_POST['headline'] ?? ''));
    $email     = trim((string)($_POST['email'] ?? ''));
    $phone     = trim((string)($_POST['phone'] ?? ''));
    $location  = trim((string)($_POST['location'] ?? ''));
    $summary   = trim((string)($_POST['summary'] ?? ''));

    // Handle JSON payloads
    $skills_json     = is_string($_POST['skills_json'] ?? null) ? $_POST['skills_json'] : json_encode($_POST['skills'] ?? [], JSON_UNESCAPED_UNICODE);
    $experience_json = is_string($_POST['experience_json'] ?? null) ? $_POST['experience_json'] : json_encode($_POST['experience'] ?? [], JSON_UNESCAPED_UNICODE);
    $education_json  = is_string($_POST['education_json'] ?? null) ? $_POST['education_json'] : json_encode($_POST['education'] ?? [], JSON_UNESCAPED_UNICODE);
    $projects_json   = is_string($_POST['projects_json'] ?? null) ? $_POST['projects_json'] : json_encode($_POST['projects'] ?? [], JSON_UNESCAPED_UNICODE);

    $cv_id = isset($_POST['cv_id']) ? intval($_POST['cv_id']) : 0;

    if ($cv_id > 0) {
        // Verify ownership and update
        $stmt = mysqli_prepare($con, "UPDATE ai_generated_cvs 
            SET job_id = ?, template_name = ?, full_name = ?, headline = ?, email = ?, phone = ?, location = ?, summary = ?, 
                skills_json = ?, experience_json = ?, education_json = ?, projects_json = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ? AND user_id = ?");
        mysqli_stmt_bind_param($stmt, "isssssssssssii", 
            $job_id, $template_name, $full_name, $headline, $email, $phone, $location, $summary,
            $skills_json, $experience_json, $education_json, $projects_json, $cv_id, $user_id);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        if ($ok) {
            echo json_encode(['ok' => true, 'message' => 'CV updated successfully!', 'cv_id' => $cv_id]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Failed to update CV: ' . mysqli_error($con)]);
        }
    } else {
        // Insert new CV record
        $stmt = mysqli_prepare($con, "INSERT INTO ai_generated_cvs 
            (user_id, job_id, template_name, full_name, headline, email, phone, location, summary, skills_json, experience_json, education_json, projects_json) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "iisssssssssss", 
            $user_id, $job_id, $template_name, $full_name, $headline, $email, $phone, $location, $summary,
            $skills_json, $experience_json, $education_json, $projects_json);
        $ok = mysqli_stmt_execute($stmt);
        $new_id = mysqli_insert_id($con);
        mysqli_stmt_close($stmt);

        if ($ok) {
            echo json_encode(['ok' => true, 'message' => 'CV saved successfully!', 'cv_id' => $new_id]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Failed to save CV: ' . mysqli_error($con)]);
        }
    }
    exit;
}

// ─────────────────────────────────────────────────────────────
// 4. ACTION: LOAD
// ─────────────────────────────────────────────────────────────
if ($action === 'load') {
    $cv_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_POST['id']) ? intval($_POST['id']) : 0);

    if ($cv_id > 0) {
        $stmt = mysqli_prepare($con, "SELECT cv.*, cj.job_title, c.company_name 
                                       FROM ai_generated_cvs cv
                                       LEFT JOIN company_jobs cj ON cv.job_id = cj.id
                                       LEFT JOIN companies c ON cj.company_id = c.id
                                       WHERE cv.id = ? AND cv.user_id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, "ii", $cv_id, $user_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        if ($row) {
            $row['skills']     = json_decode($row['skills_json'] ?? '[]', true) ?: [];
            $row['experience'] = json_decode($row['experience_json'] ?? '[]', true) ?: [];
            $row['education']  = json_decode($row['education_json'] ?? '[]', true) ?: [];
            $row['projects']   = json_decode($row['projects_json'] ?? '[]', true) ?: [];
            echo json_encode(['ok' => true, 'cv' => $row], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Saved CV not found.']);
        }
    } else {
        // List recent saved CVs for user
        $stmt = mysqli_prepare($con, "SELECT cv.id, cv.job_id, cv.template_name, cv.full_name, cv.headline, cv.updated_at, 
                                             cj.job_title, c.company_name
                                       FROM ai_generated_cvs cv
                                       LEFT JOIN company_jobs cj ON cv.job_id = cj.id
                                       LEFT JOIN companies c ON cj.company_id = c.id
                                       WHERE cv.user_id = ? 
                                       ORDER BY cv.updated_at DESC LIMIT 15");
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $list = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $list[] = $r;
        }
        mysqli_stmt_close($stmt);
        echo json_encode(['ok' => true, 'list' => $list], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Fallback
echo json_encode(['ok' => false, 'error' => 'Unsupported action.']);
