<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/admin/dbcon.php';
require_once __DIR__ . '/ai/matching.php';

// Turn on error reporting for test script
ini_set('display_errors', 1);
error_reporting(E_ALL);

function print_match_result($test_name, $user_profile, $job, $expected_high) {
    echo "========================================================\n";
    echo "TEST CASE: $test_name\n";
    echo "User Skills: " . $user_profile['user_skills'] . "\n";
    echo "Job Title: " . $job['job_title'] . "\n";
    echo "Job Category: " . $job['job_category'] . "\n";
    echo "Job Required Skills: " . $job['skills_required'] . "\n";
    
    $result = ai_match_profile_job($user_profile, $job);
    echo "FINAL SCORE: " . $result['score'] . "%\n";
    echo "EXPLANATION:\n";
    foreach ($result['explanation_points'] as $pt) {
        echo " - " . $pt . "\n";
    }
    
    if ($expected_high && $result['score'] < 50) {
        echo "❌ FAILED: Expected HIGH score but got " . $result['score'] . "\n";
    } elseif (!$expected_high && $result['score'] > 40) {
        echo "❌ FAILED: Expected LOW score but got " . $result['score'] . "\n";
    } else {
        echo "✅ PASSED\n";
    }
    echo "\n";
}

// Mock ML Candidate
$ml_candidate = [
    'user_skills' => 'Python, NumPy, Pandas, Scikit-learn, Machine Learning, Data Analysis',
    'experience' => '3 years',
    'user_degree' => 'BSc Computer Science'
];

// Mock Java Candidate
$java_candidate = [
    'user_skills' => 'Java, Spring Boot, Hibernate, REST API, MySQL',
    'experience' => '5 years',
    'user_degree' => 'BSc Computer Science'
];

// Mock Jobs
$ml_job = [
    'job_title' => 'Machine Learning Engineer',
    'job_category' => 'Data Science / AI',
    'skills_required' => 'Python, Scikit-learn, Pandas, SQL',
    'requirements' => 'Must have Python, Pandas, Scikit-learn.',
    'experience_required' => '2+ years',
    'responsibilities' => 'Build ML models',
    'job_description' => 'Great data science role.'
];

$java_job = [
    'job_title' => 'Senior Java Developer',
    'job_category' => 'Java Backend',
    'skills_required' => 'Java, Spring Boot, SQL',
    'requirements' => 'Required: Java, Spring Boot.',
    'experience_required' => '4+ years',
    'responsibilities' => 'Build backend services',
    'job_description' => 'Great backend role.'
];

// Test 1: ML Candidate vs ML Job
print_match_result("ML Candidate vs ML Job (Should be HIGH)", $ml_candidate, $ml_job, true);

// Test 2: ML Candidate vs Java Job
print_match_result("ML Candidate vs Java Job (Should be LOW)", $ml_candidate, $java_job, false);

// Test 3: Java Candidate vs Java Job
print_match_result("Java Candidate vs Java Job (Should be HIGH)", $java_candidate, $java_job, true);

// Test 4: Java Candidate vs ML Job
print_match_result("Java Candidate vs ML Job (Should be LOW)", $java_candidate, $ml_job, false);

// Test 5: Skill Alias & Boundary Test
$alias_candidate = [
    'user_skills' => 'Python 3, JS, react.js, C++',
    'experience' => '2 years',
    'user_degree' => 'BSc'
];
$alias_job = [
    'job_title' => 'Frontend Dev',
    'job_category' => 'Frontend',
    'skills_required' => 'JavaScript, React, HTML, CSS',
    'requirements' => 'Required: React and JavaScript',
    'experience_required' => '2 years',
    'responsibilities' => 'UI dev',
    'job_description' => 'Need frontend dev.'
];
print_match_result("Alias Candidate vs Frontend Job (Should match JS and React)", $alias_candidate, $alias_job, true);

// Test 6: Soft Skills Isolation
$soft_candidate = [
    'user_skills' => 'Communication, Teamwork, Leadership, Problem Solving, MS Office',
    'experience' => '2 years',
    'user_degree' => 'BBA'
];
$tech_job = [
    'job_title' => 'Backend Dev',
    'job_category' => 'Backend',
    'skills_required' => 'Java, Python, C++, SQL',
    'requirements' => 'Required: Java, Python',
    'experience_required' => '2 years',
    'responsibilities' => 'Backend dev',
    'job_description' => 'Need backend dev.'
];
print_match_result("Soft Skills Candidate vs Technical Job (Should be LOW due to penalty)", $soft_candidate, $tech_job, false);

echo "Tests Completed.\n";
