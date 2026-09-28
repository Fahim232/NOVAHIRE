<?php
/**
 * One-time migration runner for features_v9_verified_skills.sql
 * Run: php database/run_migration_v9.php
 */
require_once __DIR__ . '/../admin/dbcon.php';

if (!$con) {
    echo "ERROR: Database connection failed.\n";
    exit(1);
}

$sql_file = __DIR__ . '/features_v9_verified_skills.sql';
$sql = file_get_contents($sql_file);
if ($sql === false) {
    echo "ERROR: Could not read $sql_file\n";
    exit(1);
}

// Remove USE statement
$sql = preg_replace('/^USE\s+\w+;\s*$/mi', '', $sql);

// Execute multi-query
if (mysqli_multi_query($con, $sql)) {
    $i = 0;
    do {
        $i++;
        if ($result = mysqli_store_result($con)) {
            mysqli_free_result($result);
        }
        if (mysqli_errno($con)) {
            echo "WARNING on statement #$i: " . mysqli_error($con) . "\n";
        }
    } while (mysqli_next_result($con));

    if (mysqli_errno($con)) {
        echo "ERROR after processing: " . mysqli_error($con) . "\n";
    } else {
        echo "SUCCESS: Migration features_v9_verified_skills.sql completed.\n";
    }
} else {
    echo "ERROR: " . mysqli_error($con) . "\n";
    exit(1);
}

// Verify tables exist
$tables = ['skill_categories', 'skills', 'skill_assessments', 'skill_assessment_questions', 'skill_assessment_attempts', 'verified_skills', 'skill_progress', 'job_required_skills'];
foreach ($tables as $t) {
    $check = mysqli_query($con, "SHOW TABLES LIKE '$t'");
    if ($check && mysqli_num_rows($check) > 0) {
        echo "  ✓ Table '$t' exists\n";
    } else {
        echo "  ✗ Table '$t' NOT found\n";
    }
}

echo "\nDone.\n";
