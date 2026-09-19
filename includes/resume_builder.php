<?php
/**
 * NovaHire — Resume Builder Helper
 */

if (defined('NOVAHIRE_RESUME_BUILDER_LOADED')) return;
define('NOVAHIRE_RESUME_BUILDER_LOADED', true);

if (!isset($con)) {
    require_once __DIR__ . '/../admin/dbcon.php';
}

/**
 * Get resume data for a user
 */
function get_resume_data($con, $user_id) {
    if (!$con || !$user_id) return [];

    $data = [
        'user' => null,
        'education' => [],
        'experience' => [],
        'skills' => [],
        'projects' => [],
        'certificates' => []
    ];

    // Fetch user info
    $stmt = mysqli_prepare($con, "SELECT * FROM user_info WHERE id = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $data['user'] = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
    }

    if (!empty($data['user']['user_skills'])) {
        $data['skills'] = array_map('trim', explode(',', $data['user']['user_skills']));
    }

    return $data;
}

/**
 * Save resume data
 */
function save_resume_data($con, $user_id, $data) {
    if (!$con || !$user_id) return false;

    if (isset($data['about'])) {
        $stmt = mysqli_prepare($con, "UPDATE user_info SET about = ? WHERE id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "si", $data['about'], $user_id);
            $res = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            return $res;
        }
    }
    return true;
}

/**
 * Get available resume templates
 */
function get_resume_templates() {
    return [
        'professional' => [
            'name' => 'Professional',
            'desc' => 'Clean, structured layout suitable for corporate and traditional roles.',
            'thumbnail' => 'assets/images/template-professional.png',
            'icon' => 'fa-file-lines',
            'preview_color' => '#1a56db'
        ],
        'modern' => [
            'name' => 'Modern Minimal',
            'desc' => 'Sleek two-column design ideal for tech and creative professionals.',
            'thumbnail' => 'assets/images/template-modern.png',
            'icon' => 'fa-laptop-code',
            'preview_color' => '#0ea5e9'
        ],
        'executive' => [
            'name' => 'Executive',
            'desc' => 'Sophisticated typography focused on leadership experience.',
            'thumbnail' => 'assets/images/template-executive.png',
            'icon' => 'fa-user-tie',
            'preview_color' => '#059669'
        ],
        'creative' => [
            'name' => 'Creative',
            'desc' => 'Vibrant accent colors highlighting skills and project portfolios.',
            'thumbnail' => 'assets/images/template-creative.png',
            'icon' => 'fa-wand-magic-sparkles',
            'preview_color' => '#db2777'
        ]
    ];
}

/**
 * Render resume HTML template
 */
function render_resume_template($resume_data, $template = 'professional') {
    $u = $resume_data['user'] ?? [];
    $name = htmlspecialchars($u['username'] ?? 'Your Name');
    $email = htmlspecialchars($u['email'] ?? 'email@example.com');
    $phone = htmlspecialchars($u['phone'] ?? '+880 1XXXXXXXXX');
    $about = htmlspecialchars($u['about'] ?? 'Driven professional with expertise in problem-solving and collaboration.');
    $skills = $resume_data['skills'] ?? [];

    $skills_html = '';
    foreach ($skills as $s) {
        $skills_html .= '<span style="display:inline-block;padding:4px 10px;margin:3px;background:#e2e8f0;border-radius:4px;font-size:12px;">' . htmlspecialchars($s) . '</span>';
    }

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{$name} — Resume</title>
<style>
body { font-family: 'Helvetica Neue', Arial, sans-serif; color: #1e293b; margin: 0; padding: 40px; background: #fff; line-height: 1.5; }
.header { border-bottom: 2px solid #1a56db; padding-bottom: 20px; margin-bottom: 24px; }
.header h1 { margin: 0 0 8px; font-size: 28px; color: #0f172a; }
.header .contact { font-size: 14px; color: #64748b; }
.section { margin-bottom: 24px; }
.section h2 { font-size: 16px; text-transform: uppercase; color: #1a56db; margin-bottom: 10px; letter-spacing: 0.5px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; }
.section p { margin: 0 0 10px; font-size: 14px; color: #334155; }
</style>
</head>
<body>
<div class="header">
    <h1>{$name}</h1>
    <div class="contact">{$email} &bull; {$phone}</div>
</div>
<div class="section">
    <h2>Professional Summary</h2>
    <p>{$about}</p>
</div>
<div class="section">
    <h2>Skills & Competencies</h2>
    <div>{$skills_html}</div>
</div>
</body>
</html>
HTML;
}
