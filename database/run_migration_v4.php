<?php
/**
 * One-time migration runner for features_v4_assessment.sql
 * Run: php database/run_migration_v4.php
 * Delete this file after successful execution.
 */
require_once __DIR__ . '/../admin/dbcon.php';

if (!$con) {
    echo "ERROR: Database connection failed.\n";
    exit(1);
}

$sql_file = __DIR__ . '/features_v4_assessment.sql';
$sql = file_get_contents($sql_file);
if ($sql === false) {
    echo "ERROR: Could not read $sql_file\n";
    exit(1);
}

// Remove USE statement (already connected to the correct DB)
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
        echo "SUCCESS: Migration features_v4_assessment.sql completed.\n";
    }
} else {
    echo "ERROR: " . mysqli_error($con) . "\n";
    exit(1);
}

// Verify tables exist
$tables = ['assessment_sessions', 'assessment_responses', 'assessment_events'];
foreach ($tables as $t) {
    $check = mysqli_query($con, "SHOW TABLES LIKE '$t'");
    if ($check && mysqli_num_rows($check) > 0) {
        echo "  ✓ Table '$t' exists\n";
    } else {
        echo "  ✗ Table '$t' NOT found\n";
    }
}

// Verify columns on company_job_questions
$col_check = mysqli_query($con, "SHOW COLUMNS FROM company_job_questions LIKE 'question_type'");
if ($col_check && mysqli_num_rows($col_check) > 0) {
    echo "  ✓ Column 'question_type' exists on company_job_questions\n";
} else {
    echo "  ✗ Column 'question_type' NOT found on company_job_questions\n";
}

$col_check2 = mysqli_query($con, "SHOW COLUMNS FROM company_job_questions LIKE 'ideal_answer'");
if ($col_check2 && mysqli_num_rows($col_check2) > 0) {
    echo "  ✓ Column 'ideal_answer' exists on company_job_questions\n";
} else {
    echo "  ✗ Column 'ideal_answer' NOT found on company_job_questions\n";
}

echo "\nDone. You can delete this file now.\n";
