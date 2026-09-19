<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/admin/dbcon.php';
require_once __DIR__ . '/ai/matching.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$user_profile = [
    'user_skills' => 'Python, HTML, CSS, Django, Machine Learning, AI',
    'experience' => '', // Assuming empty as placeholder was shown
    'user_degree' => 'BSc'
];

$php_job = [
    'job_title' => 'php developer',
    'job_category' => 'Software Development',
    'skills_required' => 'PHP, HTML, CSS',
    'requirements' => 'PHP',
    'experience_required' => '3 year'
];

$python_job = [
    'job_title' => 'Python Developer',
    'job_category' => 'Software Development',
    'skills_required' => 'FastAPI, Python, MYSQL, Django',
    'requirements' => 'Python',
    'experience_required' => '3 year'
];

echo "=== USER PROFILE ===\n";
echo "Skills: " . $user_profile['user_skills'] . "\n\n";

echo "=== PHP JOB MATCH ===\n";
$res_php = ai_match_profile_job($user_profile, $php_job);
echo "Score: " . $res_php['score'] . "%\n";
foreach ($res_php['explanation_points'] as $pt) {
    echo " - $pt\n";
}
echo "\n";

echo "=== PYTHON JOB MATCH ===\n";
$res_python = ai_match_profile_job($user_profile, $python_job);
echo "Score: " . $res_python['score'] . "%\n";
foreach ($res_python['explanation_points'] as $pt) {
    echo " - $pt\n";
}
echo "\n";
